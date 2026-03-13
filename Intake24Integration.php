<?php

namespace Intake24\Intake24Integration;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Project;
use Records;

class Intake24Integration extends AbstractExternalModule
{

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);
        $survey_url     = $settings['survey_url']['value'];
        $secret_key     = $settings['secret_key']['value'];
        $redirect_url   = $settings['redirect_url']['value'];
        $user_id        = $settings['user_id']['value'];

        $calculated_user_id = $_POST[$user_id]; // the the value of the calculated user id
        
        $triggering_instrument_name = $settings['triggering_instrument_name']['value'];
        $generated_intake24_url     = $settings['generated_intake24_url']['value'];

        if ($instrument == $triggering_instrument_name) {
            // check if the URL has been generated before
            $recordData = \Records::getData($project_id, 'array', array($record));

            $existing_data = $recordData[$record][$event_id][$generated_intake24_url];

            if (!$existing_data)
            {
                // if not, generate the payload

                // Create token header as a JSON string
                $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);

                // Create token payload as a JSON string
                $payload = json_encode(['user' => $calculated_user_id, 'redirect' => $redirect_url]);

                // Encode Header to Base64Url String
                $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));

                // Encode Payload to Base64Url String
                $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

                // Create Signature Hash
                $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret_key, true);

                // Encode Signature to Base64Url String
                $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

                // Create JWT
                $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

                $generated_url_value = $survey_url . "?createUser=" . $jwt;

                $event_name = REDCap::getEventNames(true, true, $event_id);
                $record_id_field = REDCap::getRecordIdField();

                // record_id can be renamed so we cannot hard code it.
                $arrVarNames = array_merge(
                    array($record_id_field => $record,
                        'redcap_event_name' => $event_name,
                        $generated_intake24_url => $generated_url_value
                    )
                );

                $saveResponse = $this::saveMyData($project_id, $arrVarNames);

                if (count((array)$saveResponse['errors'])>0) {
                    $errors = $saveResponse['errors'];

                    $errorString = stripslashes(json_encode($errors, JSON_PRETTY_PRINT));
                    $errorString = str_replace('""', '"', $errorString);

                    $message = "The " . $this->getModuleName() . " could not save $instrument form status because of the following error(s):\n\n$errorString";
                    error_log($message);
                }

                // redirect the survey to the generated URL
                if ($generated_url_value)
                {
                    $this->redirect($generated_url_value);
                }
            }
        }
    }

    private function saveMyData($project_id, $arrVarNames)
    {
        $json_data = json_encode(array($arrVarNames));
        $response = REDCap::saveData($project_id, 'json', $json_data, 'overwrite');
        return $response;
    }

    /**
     * Redirects user to the given URL.
     *
     * This function basically replicates redirect() function, but since EM
     * throws an error when an exit() is called, we need to adapt it to the
     * EM way of exiting.
     */
    protected function redirect($url) {
        if (headers_sent()) {
            // If contents already output, use javascript to redirect instead.
            echo '<script>window.location.href="' . REDCap::escapeHtml($url) . '";</script>';
        }
        else {
            // Redirect using PHP.
            header('Location: ' . REDCap::escapeHtml($url));
        }

        $this->exitAfterHook();
    }


}
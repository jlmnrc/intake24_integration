<?php

namespace Intake24\Intake24Integration;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use RestUtility;
use Project;
use Records;

class Intake24Integration extends AbstractExternalModule
{
    private $projectId;
    private $eventId;
    private $request;
    private $record;

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);
        $survey_url     = $settings['survey_url']['value'];
        $secret_key     = $settings['secret_key']['value'];
        $redirect_url   = $settings['redirect_url']['value'];
        $user_id        = $settings['user_id']['value'];
        $schedule_enabled = $settings['schedule-enabled']['value'];

        // Which Intake24 generation this survey runs on. Different instances may run
        // different versions, so this is configured per project. Default to v4.
        $intake24_version = $settings['intake24_version']['value'];
        if (empty($intake24_version)) {
            $intake24_version = 'v4';
        }

        $calculated_user_id = $_POST[$user_id] ?? null; // the value of the calculated user id

        $triggering_instrument_name = $settings['triggering_instrument_name']['value'];
        $generated_intake24_url     = $settings['generated_intake24_url']['value'];

        if ($instrument == $triggering_instrument_name) {

            // check if the URL has been generated before
            $recordData = \Records::getData($project_id, 'array', array($record));

            $existing_data = $recordData[$record][$event_id][$generated_intake24_url] ?? null;

            // The User ID may be a calculated field that is not part of this submit POST,
            // in which case $_POST is empty; fall back to the value saved on the record.
            // Intake24 rejects the token if "username" is empty, so this must be set.
            if (empty($calculated_user_id)) {
                $calculated_user_id = $recordData[$record][$event_id][$user_id] ?? null;
            }

            if (!$existing_data)
            {
                // if not, generate the payload

                // Create token header as a JSON string
                $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);

                // Create token payload as a JSON string. The claim names differ by version:
                //   v3: { "user": <id>, "redirect": <url> }
                //   v4: { "username": <id>, "exp": <ts>, "redirectUrl": <url> }
                // In v4 "username" is lowercase and a wrong field name makes the
                // create-user call return 400 silently. "exp" is a recommended expiry
                // (v3 tokens never expired). In v4 "redirectUrl" is only echoed back to
                // the caller, NOT used to send the participant anywhere; the post-survey
                // hand-off is a redirect step in the Intake24 survey scheme instead.
                if ($intake24_version === 'v3') {
                    $payload = json_encode(array(
                        'user'     => $calculated_user_id,
                        'redirect' => $redirect_url,
                    ));
                } else {
                    // Set the lifetime comfortably longer than your reminder window so
                    // that links reused in reminder emails stay valid.
                    $token_lifetime_days = 90;
                    $claims = array(
                        'username' => $calculated_user_id,
                        'exp'      => time() + ($token_lifetime_days * 24 * 60 * 60),
                    );
                    // redirectUrl is verified by Intake24 as a valid absolute URL, so only
                    // include it when it really is one; otherwise the create-user call is
                    // rejected. It is optional and not used for the in-app redirect (the
                    // ?redirect=recall query parameter handles that), so omitting it is safe.
                    if (!empty($redirect_url) && filter_var($redirect_url, FILTER_VALIDATE_URL)) {
                        $claims['redirectUrl'] = $redirect_url;
                    }
                    $payload = json_encode($claims);
                }

                // Encode Header to Base64Url String
                $base64UrlHeader = $this->base64UrlEncode($header);

                // Encode Payload to Base64Url String
                $base64UrlPayload = $this->base64UrlEncode($payload);

                // Create Signature Hash
                $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret_key, true);

                // Encode Signature to Base64Url String
                $base64UrlSignature = $this->base64UrlEncode($signature);

                // Create JWT
                $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

                // The token location in the link differs by version (both verified against
                // the Intake24 survey-app router, which uses HTML5 history routing):
                //   v3: <survey_url>?createUser=<TOKEN>
                //   v4: <survey_url>/create-user/<TOKEN>?redirect=recall
                //       -> route /:surveyId/create-user/:token  (NOT a #hash route)
                // The survey slug is NOT hard-coded; it comes from the survey_url setting,
                // which should match the instance path: v3 commonly /surveys/<slug>, v4
                // /survey/<slug>. "?redirect=recall" (v4) opens the recall directly.
                if ($intake24_version === 'v3') {
                    $generated_url_value = $survey_url . "?createUser=" . $jwt;
                } else {
                    $generated_url_value = $survey_url . "/create-user/" . $jwt . "?redirect=recall";
                }

                $event_name = REDCap::getEventNames(true, true, $event_id);
                // record_id can be renamed so we cannot hard code it.
                $record_id_field = REDCap::getRecordIdField();

                $arrVarNames = array_merge(
                    array($record_id_field => $record,
                        'redcap_event_name' => $event_name,
                        $generated_intake24_url => $generated_url_value
                    )
                );

                if ($schedule_enabled) {
                    $currDate    = new \DateTime();
                    $schedule_time1_field_name = $settings['schedule_time_1']['value'];
                    $arrVarNames = array_merge($arrVarNames,
                        array(
                            $schedule_time1_field_name => $this->calculateReminderDate($currDate->format('Y-m-d H:i:s'))
                        )
                    );
                }

                $resp = $this->saveMyData($project_id, $arrVarNames);
                $saveErrors = $this->getSaveErrors($resp);
                if ($resp === false || !empty($saveErrors)) {
                    $this->logIntake24Event("Failed when updating the JSON payload, please contact your REDCap Administrator. Errors: " . json_encode($saveErrors), $record, $event_id);
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
     * Safely extracts the error list from a REDCap::saveData() response.
     * Returns an array of error messages (empty when the save succeeded).
     *
     * This is written to be crash-proof on PHP 8+, where the released module's
     * line `count($saveResponse['errors'])` throws a fatal TypeError if 'errors'
     * is missing (count of null). Even the (array)-cast fix still fatals if the
     * response is a string, because the ['errors'] offset is read before the
     * cast. Here we normalise the response first, so no offset/count ever runs
     * against a null or a string.
     */
    private function getSaveErrors($response)
    {
        // saveData usually returns an array, but can return a JSON string.
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            $response = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($response) || !isset($response['errors'])) {
            return array();
        }
        $errors = $response['errors'];
        return is_array($errors) ? $errors : array($errors);
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
            echo '<script>window.location.href="' . $url . '";</script>';
        }
        else {
            // Redirect using PHP.
            header('Location: ' . $url);
        }

        $this->exitAfterHook();
    }

    public function updateFromIntake24() {

        $this->processApiRequest();
        return $this->formatReturnData();
    }

    /*
        Intake24 can notify external service about submitted survey.  For each survey this url can be customised in admin tool in survey section.
        Configure the return URL at Submission notification URL
        IT send POST request with these data:
        ```
{"userId":2194,"surveyId":"MFS_Test","userName":"12","userCustomData":{},"startTime":"2022-06-07T15:20:22.204+10:00","endTime":"2022-06-07T15:53:13.982+10:00","uxSessionId":"695eba91-9c77-4427-ac9a-8809770ee44d","submissionId":"3c9c8b47-e5be-4539-9ea5-d1f524272c5d","submissionCustomData":{},"meals":[{"name":"Breakfast","time":{"hours":8,"minutes":0},"customData":{},"foods":[],"missingFoods":[]},{"name":"Morning snack or drink","time":{"hours":10,"minutes":30},"customData":{},"foods":[],"missingFoods":[]},{"name":"Lunch","time":{"hours":13,"minutes":0},"customData":{},"foods":[],"missingFoods":[]},{"name":"Afternoon snack or drink","time":{"hours":16,"minutes":0},"customData":{},"foods":[],"missingFoods":[]},{"name":"Evening meal","time":{"hours":19,"minutes":0},"customData":{},"foods":[],"missingFoods":[]},{"name":"Late snack or drink","time":{"hours":22,"minutes":0},"customData":{},"foods":[],"missingFoods":[]}]}
     *
     * How to trigger (for testing):
     * curl -d "returnFormat=json&record=10" "http://localhost/api/?NOAUTH&type=module&prefix=intake24_integration&page=intake24_update&projectid=32"
     */
    protected function processApiRequest() {
        global $Proj;

        // Intake24 v4 delivers the whole completion message as a single raw JSON body.
        // Read php://input FIRST: RestUtility::processRequest() below may consume the
        // input stream, which would leave a later read of php://input empty.
        $raw_body = file_get_contents('php://input');

        // considering security/auth, anyone with the link and project_id can update the schedule date
        // but it is just the data, and they need to know the project id, the data format, only scheduling date is update-able
        $Proj = new Project($_GET['projectid'] ?? null);

        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $Proj->project_id);

        // intake24 does not have a way to hide the token, so it cannot be passed via URL as it is very sensitive
        // so we need to configure the token used for the project at the EM config
        // assumption: only authorised users can see the external module configuration
        $_POST['token'] = $settings['api_token']['value'];

        $this->request = RestUtility::processRequest(true);
        $this->projectId = $this->request->getRequestVars()['projectid']; // get it from the token auth
        $Proj = new Project($this->projectId);
        $this->eventId = $Proj->firstEventId;

        $schedule_enabled = $settings['schedule-enabled']['value'];

        if(!$this->isModuleEnabledForProject()) {
            self::errorResponse("The requested module is currently disabled on this project.");
        }

        // --- Authenticate the caller ---------------------------------------
        // Intake24 v4 signs each notification with the shared secret and sends it as
        // "Authorization: Bearer <HS256 JWT>". We verify that signature so arbitrary
        // callers who happen to know the URL + project id can no longer drive the
        // schedule. v3 does NOT sign its notifications, so verification is skipped for
        // v3 surveys (leave require_signed_notifications off for those projects).
        //
        // The notification is signed with the M2M secret. By default we reuse the
        // create-user secret (secret_key); set notification_secret only if your
        // Intake24 survey uses a different secret for external communication.
        $intake24_version = $settings['intake24_version']['value'];
        if (empty($intake24_version)) {
            $intake24_version = 'v3';
        }

        if ($intake24_version === 'v4') {
            $secret_key          = $settings['secret_key']['value'];
            $notification_secret = $settings['notification_secret']['value'];
            if (empty($notification_secret)) {
                $notification_secret = $secret_key;
            }
            $require_signed = $settings['require_signed_notifications']['value'];

            if ($this->verifyIntake24Signature($notification_secret)) {
                $this->logIntake24Event("Intake24 notification signature verified.", null, null);
            } else {
                // Logged whether or not enforcement is on, so you can confirm signatures
                // are arriving before flipping "require_signed_notifications" to ON.
                $this->logIntake24Event("Intake24 notification signature MISSING or INVALID.", null, null);
                if ($require_signed) {
                    self::sendResponse(401, "Unauthorized: notification signature missing or invalid.");
                    return;
                }
            }
        } else {
            $this->logIntake24Event("Intake24 v3 notification accepted (v3 does not sign notifications).", null, null);
        }
        // -------------------------------------------------------------------

        if (!$schedule_enabled) {
            $this->logIntake24Event("The web service is triggered but schedule is not enabled at the external module config.", null, null);
            self::errorResponse("The web service is triggered but schedule is not enabled at the external module config.");
        }

        // Decode the single JSON package sent by Intake24 v4 in the request body.
        $json_data = json_decode($raw_body);

        // Validate the payload: a malformed/empty body or a non-completion event
        // (e.g. a "session started" notification, which carries no endTime) must not
        // be processed. Configure Intake24 to fire on "Survey session submitted".
        if (!is_object($json_data) || empty($json_data->userName) || empty($json_data->endTime)) {
            $this->logIntake24Event("Received an Intake24 notification with no userName/endTime; ignoring. Body: " . substr($raw_body, 0, 500), null, null);
            self::errorResponse("Invalid or incomplete Intake24 submission payload. Ensure the notification event is 'Survey session submitted'.");
        }

        $record_id =  $json_data->userName;
        $json_data_start_time = $json_data->endTime;
        $tmpTime = explode('T',$json_data_start_time);
        $survey_complete_time = implode('/',explode('-',$tmpTime[0])).' '.substr($tmpTime[1],0,5);

        // log here to indicate it is being triggered
        $this->logIntake24Event("Intake24 survey completed time is $survey_complete_time", $record_id, null);

        // retrieve the record
        $recordData = \Records::getData($this->projectId, 'array', array($record_id));

        if (!empty($recordData))
        {
            // we need the event name for saving even it is not longitudinal
            $event_name = REDCap::getEventNames(true, true, $this->eventId);
            // The record ID field can be renamed per project, so never hard-code it.
            // $Proj is built above from the project id, and Project::$table_pk holds the
            // primary-key (record ID) field name regardless of how the request arrived.
            $record_id_field = $Proj->table_pk;

            // schedule_time_1
            $schedule_time2_field_name = $settings['schedule_time_2']['value'];
            $schedule_time3_field_name = $settings['schedule_time_3']['value'];

            // completed_time_1
            $completed_time1_field_name = $settings['completed_time_1']['value'];
            $completed_time2_field_name = $settings['completed_time_2']['value'];
            $completed_time3_field_name = $settings['completed_time_3']['value'];

            $survey_completed_time1 =  $recordData[$record_id][$this->eventId][$completed_time1_field_name] ?? null;
            $survey_completed_time2 =  $recordData[$record_id][$this->eventId][$completed_time2_field_name] ?? null;
            $survey_completed_time3 =  $recordData[$record_id][$this->eventId][$completed_time3_field_name] ?? null;

            // mark it as completed when all 3 recalls are completed
            $scheduling_instrument_name = $settings['scheduling_instrument_name']['value'];

            if (!empty($survey_completed_time3)) {
                // this is the end but someone still submitted the latest one
                // we just log it and ignore
                $this->logIntake24Event("There are already 3 recall surveys completed, ignoring this notification.", $record_id, null);

            } else if (!empty($survey_completed_time2)) {
                // survey 3 is empty but survey 2 is entered
                // thus we assume this submission is for survey 3
                $arrVarNames = array_merge(
                    array($record_id_field => $record_id,
                        'redcap_event_name' => $event_name,
                        $completed_time3_field_name => $survey_complete_time,
                        $scheduling_instrument_name . '_complete' => "2"
                    )
                );
                $this->logIntake24Event("Saved to recall 3 completed time", $record_id, null);
            } else if (!empty($survey_completed_time1)) {
                // survey 2 is empty, but survey 1 is entered
                // thus we assume this is for survey 2
                $arrVarNames = array_merge(
                    array($record_id_field => $record_id,
                        'redcap_event_name' => $event_name,
                        $completed_time2_field_name => $survey_complete_time,
                        $schedule_time3_field_name => $this->calculateReminderDate($survey_complete_time)
                    )
                );
                $this->logIntake24Event("Saved to recall 2 completed time", $record_id, null);
            } else {
                // survey 1 is empty, so we assume this is for survey 1
                $arrVarNames = array_merge(
                    array($record_id_field => $record_id,
                        'redcap_event_name' => $event_name,
                        $completed_time1_field_name => $survey_complete_time,
                        $schedule_time2_field_name => $this->calculateReminderDate($survey_complete_time)
                    )
                );
                $this->logIntake24Event("Saved to recall 1 completed time", $record_id, null);
            }

            if (!empty($arrVarNames)) {
                $resp = $this->saveMyData($this->projectId, $arrVarNames);
                $saveErrors = $this->getSaveErrors($resp);
                if ($resp === false || !empty($saveErrors)) {
                    self::errorResponse("Failed when updating the JSON payload, please contact your REDCap Administrator. Errors: " . json_encode($saveErrors));
                }
            }
        } else {
            self::errorResponse("Cannot find record $record_id.");
        }
    }

    private function calculateReminderDate($completedTime)
    {
        // $completedTime arrives as a date/time STRING (e.g. "2026-06-13 14:30:00" from
        // redcap_save_record, or "2026/06/13 14:30" from the Intake24 notification).
        // date()'s 2nd argument must be a Unix timestamp, so convert first. The previous
        // version passed the string straight to date('N', ...), which PHP coerced to a
        // small integer and always evaluated to Thursday — meaning the "Friday" branch
        // fired for every record.
        $ts = strtotime($completedTime);
        if ($ts === false) {
            // Could not parse the input; fall back to now so we still return a valid value.
            $ts = time();
        }

        // ISO-8601 day of week: Monday = 1 ... Friday = 5 ... Sunday = 7.
        if ((int) date('N', $ts) === 5) {
            // Completed on a Friday: send the reminder on Sunday morning (10:00),
            // i.e. 2 days later, rather than the following Monday.
            return date('Y-m-d', strtotime('+2 days', $ts)) . ' 10:00:00';
        }

        // Otherwise the reminder goes out 3 days after completion, at the same time of day.
        return date('Y-m-d H:i:s', strtotime('+3 days', $ts));
    }

    /**
     * Returns the raw Authorization header, or null if absent. Tries several
     * sources because servers expose it inconsistently (Apache vs nginx/php-fpm,
     * mod_rewrite, etc.). On nginx/php-fpm you may need to forward it explicitly,
     * e.g. fastcgi_param HTTP_AUTHORIZATION $http_authorization;
     */
    private function getAuthorizationHeader() {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return trim($value);
                }
            }
        }
        return null;
    }

    private function base64UrlEncode($data) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function base64UrlDecode($data) {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Verifies the HS256 Bearer token Intake24 v4 attaches to its submission
     * notification. Returns true ONLY if the token is present, well-formed, uses
     * HS256, is signed with $secret, and (if it carries an exp claim) has not
     * expired. Signature verification proves the request came from a holder of the
     * shared secret (i.e. Intake24), which is what closes the open-webhook gap.
     */
    private function verifyIntake24Signature($secret) {
        if (empty($secret)) {
            return false; // nothing to verify against
        }

        $auth = $this->getAuthorizationHeader();
        if ($auth === null || stripos($auth, 'bearer ') !== 0) {
            return false;
        }

        $jwt = trim(substr($auth, 7));
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        list($b64Header, $b64Payload, $b64Signature) = $parts;

        // Accept HS256 only. Rejecting everything else blocks the classic
        // "alg":"none" bypass and algorithm-confusion attacks.
        $header = json_decode($this->base64UrlDecode($b64Header), true);
        if (!is_array($header) || empty($header['alg']) || strtoupper($header['alg']) !== 'HS256') {
            return false;
        }

        // Recompute the signature over header.payload and compare in constant time.
        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $b64Header . '.' . $b64Payload, $secret, true)
        );
        if (!hash_equals($expected, $b64Signature)) {
            return false;
        }

        // Honour an expiry if one is present (allow 60s of clock skew).
        $payload = json_decode($this->base64UrlDecode($b64Payload), true);
        if (is_array($payload) && isset($payload['exp']) && time() > ((int) $payload['exp'] + 60)) {
            return false;
        }

        return true;
    }

    protected function logIntake24Event($detail, $record, $event_id) {
        $title = "Intake24 Integration";

        \REDCap::logEvent($title, $detail, '', $record, $event_id);

    }

    protected function isModuleEnabledForProject() {
        return ExternalModules::getProjectSetting($this->PREFIX, $this->projectId, ExternalModules::KEY_ENABLED);
    }

    protected function formatReturnData() {
        $response = "Completed successfully";
        return $response;
    }

    protected static function errorResponse($message) {
        self::sendResponse(400, $message);
    }

    protected static function sendResponse($status=200, $response='') {
        RestUtility::sendResponse($status, $response);
    }


}
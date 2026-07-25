<?php

/**
 * No-auth web-service endpoint that receives Intake24 "Survey session submitted"
 * notifications. Declared in config.json under both:
 *   "no-auth-pages": ["intake24_update"]  (accessible without a REDCap login)
 *   "no-csrf-pages": ["intake24_update"]  (REDCap 16+ CSRF-exempts external POSTs)
 *
 * Reached at:
 *   <redcap>/api/?type=module&prefix=intake24_integration&page=intake24_update&projectid=<PID>
 *
 * REDCap serves module pages from a file named after the page, so this file must
 * exist for the endpoint to route. It simply delegates to the module method.
 *
 * @var \Intake24\Intake24Integration\Intake24Integration $module
 */

$module->updateFromIntake24();

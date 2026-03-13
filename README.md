### REDCap External Module
********************************************************************************
# Intake24 Survey Integration
********************************************************************************
## Introduction
[Intake24](https://intake24.com/) is a nutrient survey tool that provides a graphical food/drink survey for the research community.  REDCap is usually used to capture participant information but then re-directed to the intake24.com survey for the nutrition survey data capture.  This external module will link the REDCap record with the user in intake24.  The module will create a new user at intake24.com once they have completed their survey at REDCap (e.g. consent, demographics, etc) and link it with the record id of REDCap.  The module will then store the unique intake24.com URL for that particular record for reference.

## Limitation
- Does not support longitudinal events.

## External Module Configurable Variables
The following are the fields the user needs to configure before they can use the External Module.
### Intake24.com Survey URL
The URL of your intake24 survey, e.g. https://intake24.com/surveys/LEHS_Survey.
### Intake24 Secret Key for signing the JSON payload
This value should be the same as the 'JWT secret for user generation' configured at admin.intake24.com of your survey, e.g. sIdjs4#2kK!js.
### Intake24 Redirect URL stored in the JSON payload
When the intake24 survey is completed, the user will be directed to this URL.
### Intake24 User ID stored in the JSON payload
Which REDCap field will be used as the user ID in intake24, this should be a unique ID in your REDCap project, e.g. record_id.
### The instrument/form name that will trigger the creation of the intake24 URL
Which REDCap instrument upon clicking 'Submit' will redirect the user to the intake24 survey.
### The field name that stores the generated intake24 URL with the JSON payload
The REDCap text field will store the generated intake24 URL, this could be used to send a reminder to complete the survey.  This field could be hidden or not accessible by normal users.

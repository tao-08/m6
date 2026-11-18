Subject: Re: [Action Needed] Your Google APIs Verification Request (Project: awesome-habitat-475212-m7 / 152180724131)

Hello Third Party Data Safety Team,

Thank you for the checklist. I audited AbbeyRoad.online (https://abbeyroad.online/) against every item. Summary below.

■ Scopes (exactly match the Cloud Console configuration)
1. https://www.googleapis.com/auth/calendar.calendarlist.readonly
   Lists the calendars the user has selected in Google Calendar, so events are read only from those. No narrower scope can list calendars.
2. https://www.googleapis.com/auth/calendar.events
   (a) Reads event times and titles from those calendars to remove busy times from practice candidates. Titles are shown only to the user (free/busy would not return them).
   (b) Creates, updates and deletes one event per decided practice on the user's own primary calendar. Only events created by the app are modified.
   Narrower alternatives are not viable: events.readonly cannot write; calendar.app.created cannot read existing events; calendar.events.owned would exclude subscribed/shared calendars that users rely on for their busy times.
Both scopes are Sensitive (no Restricted scopes), so CASA does not apply. Both are used by production-ready, user-facing features.

■ Demo video (YouTube, unlisted)
<YOUTUBE_UNLISTED_URL>
It shows the full OAuth consent screen with both scopes expanded (client_id visible in the URL bar), reading of events, creation / update / deletion of an event with the result shown in the source Google Calendar, and disconnecting (token revocation and data deletion).

■ Test access (no phone verification, no payment)
URL: https://abbeyroad.online/
App test account  - ID: <TEST_ID> / Password: <TEST_PASSWORD>
Google test account (added as a test user, if applicable) - <TEST_GOOGLE_EMAIL> / <TEST_GOOGLE_PASSWORD>
Navigation: log in -> "My Schedule" (マイスケジュール) -> "Calendar integration" (カレンダー連携) -> "Connect with Google" (Google で連携).
To see event creation: open the scheduling page <TEST_SCHED_URL> -> press "Decide" (決定); the event appears in the connected Google Calendar. Changing or cancelling the decision updates / deletes it.
No phone verification, payment or other blocker is required.

■ Privacy policy (https://abbeyroad.online/privacy#google)
Section 3 states what Google data is accessed (event start/end times and titles, account identifier, OAuth tokens), how it is used (only to exclude busy times and to add decided practices), that it is never sold, shared with third parties, or used for advertising, how it is protected (titles and tokens encrypted at rest; human access limited to consent, security investigation or legal obligation), and retention/deletion (replaced on each sync; deleted immediately on "Disconnect" or account withdrawal, together with revocation of the Google token; deletion on request is also possible).
It also includes the Limited Use statement: "The use and transfer to any other app of information received from Google APIs will adhere to the Google API Services User Data Policy, including the Limited Use requirements."

■ AI/ML
Google user data is never sent to any AI/ML service and is never used to develop, improve or train AI/ML models. (The app's AI features process only images and music metadata supplied by users, never Calendar data.)

■ Other
- Live app: the demo was recorded without deploying unverified scopes to general users; the publishing status is unchanged.
- Not a Data Portability, Drive, Gmail, Chat, Meet, Photos or YouTube API integration.

Please let me know if anything else is needed.

Best regards,
<YOUR_NAME>

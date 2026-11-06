# Reference flowcharts

The original PNGs from `eBaryo_Flowcharts (1).zip` are preserved in [`flowcharts/`](flowcharts/). They describe the resident, admin, verification, request lifecycle, payment, template, and pickup-expiry flows used by this implementation.

For setup and external provider configuration, see the project [README](../README.md).

The clarified authentication flow is Loading System, Landing Page, then Login or Register. Login checks email before password, displays Invalid with OK on failure, and Login Success with OK before proceeding to the authenticated destination. Registration fields stay in the supplied order: first name, last name, email, contact number, barangay, sitio, National ID front/back, password and confirm password. The continuation at connectors C and D is awaiting clarification; this explanation does not establish a new approval or verification step.

The seven diagrams in [`admin-flowcharts/`](admin-flowcharts/) are the current admin reference. They take precedence over the older `B-*` diagrams for the admin interface. Admin functionality must stay within these supplied flows.

The dashboard displays pending users, total requests and All requests. Residents support name/email search, registration filters and viewing resident information. Resident editing, account activation/deactivation/deletion, password resets, document template management, document reactivation, system configuration and report analytics are not available. Reports display request history and document content, with individual and all-as-read behavior. Settings contain only change password, email updates and in-app notification preferences.

The requests page supports resident name/ID search, document/status/payment/date filtering and the archive file view. There is no manual archive or restore action in the supplied diagrams. Request-action labels in image 3 and whether the registration Approved/Reject dropdown filters or makes a decision are awaiting user clarification; do not infer additional branches from the older diagrams.

Notification attachments are private and downloadable by their recipient or an admin. Document drafts support Save draft and Save & print. Run `php artisan migrate` when updating an existing installation to add the archive, report read and notification attachment fields.

# eBaryo interface guide

The Laravel/React app and the standalone PHP portal share a calm green visual direction, white surfaces, clear headings, and practical service guidance.

## Sources

- Laravel: `resources/css/modern.css` defines the visual theme and guidance components. `resources/js/app.jsx` contains the landing page and dashboard composition.
- Standalone PHP: the final shared theme section of `user-side/assets/style.css` styles both resident and admin pages. Feature-specific editor and print rules stay in `user-side/admin/assets/admin.css`.

## Design rules

- Primary green: `#14785d`; heading text: `#223c32`; secondary text: `#596e65`.
- Page backgrounds use light gray; cards use white with a subtle border. Use light green for welcome panels and service guidance.
- Use one prominent primary action per task. Secondary actions use white backgrounds and green borders. Reserve red for destructive actions.
- Keep controls at least 44 pixels tall. Preserve visible keyboard focus, semantic labels, and existing confirmation dialogs.
- Keep body text readable and explanatory copy brief. Present status as text, so color is never the only signal.
- Use consistent 12–16 pixel card corners and 20–30 pixel internal spacing. Allow form labels and navigation text to wrap on small screens.
- Use a single column for guidance and document cards on mobile. Keep wide data tables inside their scrolling container.
- Keep document printing independent of workspace decoration.

## Screen organization

The landing page explains the service and shows the three steps: create an account, request a document, and track/collect. Dashboards introduce the workspace, summarize activity, and expose common next actions. Resident guidance links directly to notifications for updates and pickup instructions.

## Verification

Run `npm run build` and `php artisan test` for the Laravel application. The existing scripts in `user-side/tests` cover resident and administrator flows with dedicated test databases. Browser checks should cover desktop and narrow mobile widths and verify that the shared stylesheet loads successfully.

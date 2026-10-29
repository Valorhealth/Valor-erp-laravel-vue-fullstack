# macOS Browser Compatibility Scope

> Last reviewed: 29 October 2026

## Supported browsers

- macOS: current Chrome and Edge
- macOS: Safari 15.4 or newer
- Windows: current Chrome and Edge (existing behavior remains supported)

The frontend uses progressive enhancement. A browser that does not provide a usable native date, month, time, or date-time control receives a select-based fallback while the API payload remains ISO formatted.

## Changes included

### Date and time fields

All native date-related fields now use `CompatDateTimeInput.vue`:

- work calendar and task assignment
- FibroScan booking requests, availability, and filters
- Sales Call entry, history, report, and monthly checklist
- dashboard report filters
- audit log filters
- notice scheduling
- quotation and billing dates
- system administration dates

Supported output formats are `YYYY-MM-DD`, `YYYY-MM`, `HH:mm`, and `YYYY-MM-DDTHH:mm`. The fallback prevents invalid day/month combinations, respects minimum and maximum bounds, and allows optional values to be cleared.

### Browser API fallbacks

- Clipboard: uses the Clipboard API on supported secure pages and a selection-based fallback when Safari rejects or does not expose it.
- File download: delays Blob URL cleanup so Safari can start the download reliably.
- Responsive menu: supports both modern and older Safari media-query listener APIs.
- Long Sales Call tables: loads remaining data when `IntersectionObserver` is unavailable.

### CSS and rendering

- Added macOS/iOS text-size and font-smoothing normalization.
- Added WebKit styling for date/time values and file-upload buttons.
- Replaced the compatibility-sensitive `:has()` chart selector.
- Kept wide tables horizontally scrollable rather than changing the desktop workflow.
- Existing `100vh` fallbacks remain before `100dvh` declarations.

### Time-zone consistency

- Export filenames use the user's local calendar date instead of UTC, preventing a one-day filename difference near midnight.

## Verification performed

- Vue TypeScript production build (`vue-tsc` and `vite build`).
- Static scan confirms there are no remaining direct `date`, `month`, `time`, or `datetime-local` inputs in Vue views.
- Headless WebKit smoke test covers authentication, the work calendar, month selection, the FibroScan calendar, booking date-time controls, and JavaScript console errors.

## Final acceptance test on a Mac

Before production sign-off, test one real MacBook because Playwright WebKit is close to Safari but is not the macOS Safari application itself:

1. Sign in and sign out.
2. Select date, month, time, and date-time values on the pages listed above.
3. Upload an Excel file, an image, and a supporting document.
4. Download PDF, Excel, PO, quotation, and credit documents.
5. Copy a quotation acceptance link.
6. Open/close the sidebar and scroll wide tables.
7. Print or export a dashboard and quotation.

Any browser-specific failure found during this acceptance test should be recorded with the macOS version, browser name/version, page, action, and screenshot.

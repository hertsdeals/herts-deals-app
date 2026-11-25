# Herts Deals app – notes for Claude

The iPhone and Android app for Herts Deals (hertsdeals.co.uk), a local guide to deals, independent businesses and town information across Hertfordshire. The owner is Dan, who runs the business alone and isn't a developer: explain things in plain English and keep changes simple to review.

## How it fits together
- `www/` is the app itself: one page (`index.html`), plus `native.js`, fonts and images. It is wrapped for the stores with Capacitor (`android/`, `ios/`).
- Data comes live from the website: `https://hertsdeals.co.uk/wp-json/hd/v1/app` (deals, businesses, towns, categories, events, guides). `www/feed.json` is a bundled copy for first launch offline; Codemagic refreshes it at build time.
- The feed is built in WordPress (Code Snippets, "Herts Deals app: data feed", snippet 177), not in this repo. If a change needs new data, say so: it has to be made on the website.
- Builds run in Codemagic (`codemagic.yaml`): Android test APK, Android release (Google Play internal track), iPhone release (TestFlight).

## Rules
- Accuracy first. Never invent deals, prices, opening times or facts; show only what the feed provides.
- Nothing may slow down the website. Don't add heavy requests to the feed; cache on the phone.
- Brand: navy `#0D3B66`, red `#BC1B22`. Headings in Caudex, everything else in Inter (both bundled in `www/fonts`).
- A "star" on a deal means it is exclusive to Herts Deals. Don't use the word "Verified".
- Never commit keys, passwords or signing files. Store keys live in Codemagic.
- After changing `www/`, run `npx cap sync` so the native projects pick it up.

## Notifications, towns and favourites
- No accounts. Choices are stored on the phone: `hd_prefs` (towns, interests, notify, onboarded), `hd_favs` (favourite businesses), `hd_saved` (saved deals).
- First open shows the welcome screens (towns, interests, notifications). Settings is at `#settings`; the "My Herts" tab has For you / Favourites / Saved.
- Push uses `@capacitor-firebase/messaging` with Firebase topics: `all`, `town_<location id>`, `biz_<company id>`, `cat_<category id>`. Logic is in `www/native.js` (`hdNative.push`).
- The website sends a notification when a deal goes live: Code Snippet "Herts Deals app: push notifications" (copy in `website/push-notifications-snippet.php`). The Firebase key is uploaded at Tools > App notifications (stored in the option `hd_fcm_service_account`, or `HD_FCM_SERVICE_ACCOUNT` in wp-config.php); it does nothing without a key. Quiet hours 8pm to 9am. Status at Tools > App notifications.
- Never commit the Firebase service account key.
- `native.js` must load before the main app script: when there is a saved feed the app starts immediately, and `hdNative.ready()` (which hides the splash screen, `launchAutoHide: false`) has to exist by then. native.js also hides the splash after 5s as a safety net.
- Deal grids use `repeat(2,minmax(0,1fr))`; plain `1fr` let long business names widen a column and the page scroll sideways.

## App icon
- White background, red logo (`#BC1B22`). Vector source: `assets/source/herts-deals-logo-traced.svg` (traced from the website logo). `assets/icon-only.png` (logo ~74% wide), `assets/icon-foreground.png` (Android adaptive, logo ~78% wide, because the template insets it 16.7%), `assets/icon-background.png` (white).
- Regenerate with `npx capacitor-assets generate --iconBackgroundColor '#ffffff' --splashBackgroundColor '#ffffff' --splashBackgroundColorDark '#0D3B66'`, then delete the `icons/` folder and `www/manifest.json` it creates and revert its reformatting of `AndroidManifest.xml`.

## Accounts and store setup (status 25 Nov 2026)
- Apple: team Herts Deals Ltd, Team ID 2Q455TPTMZ. App ID `uk.co.hertsdeals.app` registered with Push Notifications. App Store Connect app "Herts Deals" (Apple ID 6839207282, SKU HERTSDEALS1, English U.K.). App Privacy published: Device ID only, App Functionality, not linked, no tracking. Privacy policy https://hertsdeals.co.uk/privacy-policy/ (section 8 covers the app).
- APNs key "Herts Deals push", Key ID B54QG8HMXC (Sandbox & Production), uploaded to Firebase for development and production. Dan holds the .p8; it can't be downloaded again.
- Firebase project `herts-deals`. The WordPress push snippet has its service account key installed and checked.
- Google Play: fees paid, waiting on account verification. Data safety form still to do after that (answer to match Apple: device ID for app functionality only).
- Codemagic (Dan's personal account, 500 free macOS minutes a month; builds can queue ~15 min). Android test build works. The iPhone TestFlight workflow still needs an App Store Connect API key added as the Codemagic integration "Herts Deals". The Android release workflow needs the upload keystore and the Google Play service account.

## Where things stand
- Work is on branch `push-notifications-and-favourites` (towns, interests, notifications, favourites, My Herts, all towns listed, splash fix, grid fix, new icon). Merge into `main` once Dan has tested an Android build from the branch on his phone.
- Debug APKs from Codemagic aren't signed with the same key each time, so Dan must uninstall the old test app before installing a new one.
- To do: Dan tests build #5 (reopen several times, test notification to topic `town_249` = Hertford from the Firebase console); then iPhone TestFlight setup; Play Data safety; store listings (screenshots, descriptions); merge to main and submit.

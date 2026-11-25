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

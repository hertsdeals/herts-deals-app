/* Native glue for the store app. Safe to load in a normal browser: it does nothing there. */
(() => {
  const C = window.Capacitor;
  const isNative = !!(C && C.isNativePlatform && C.isNativePlatform());
  const P = (C && C.Plugins) || {};
  let readyDone = false;

  window.hdNative = {
    isNative,
    ready() {
      if (readyDone || !isNative) return; readyDone = true;
      try { P.StatusBar && P.StatusBar.setStyle({ style: 'LIGHT' }); } catch (e) {}
      setTimeout(() => { try { P.SplashScreen && P.SplashScreen.hide({ fadeOutDuration: 250 }); } catch (e) {} }, 150);
    },
    share(url) {
      if (isNative && P.Share) return P.Share.share({ url, dialogTitle: 'Share' }).catch(() => {});
      if (navigator.share) return navigator.share({ url }).catch(() => {});
    }
  };
  if (!isNative) return;

  /* Web links open in an in-app browser so people stay in the app. tel:, mailto: and maps open natively. */
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]'); if (!a) return;
    const href = a.getAttribute('href');
    if (!/^https?:\/\//i.test(href)) return;
    e.preventDefault();
    const maps = /google\.[a-z.]+\/maps|maps\.apple\.com|goo\.gl\/maps/i.test(href);
    if (maps || !P.Browser) { window.open(href, '_system'); return; }
    P.Browser.open({ url: href, toolbarColor: '#ffffff', presentationStyle: 'popover' }).catch(() => window.open(href, '_system'));
  }, true);

  /* Android back button: close sheet, then go back from a detail page, then home tab, then leave. */
  if (P.App) P.App.addListener('backButton', () => {
    const app = window.hdApp || {};
    if (app.closeSheet && app.closeSheet()) return;
    if (app.isDetail && app.isDetail()) { const b = document.querySelector('[data-act="back"]'); if (b) b.click(); else history.back(); return; }
    if (app.tab && app.tab() !== 'home') { location.hash = 'home'; return; }
    P.App.exitApp();
  });

  /* Pull the latest deals when the app comes back to the foreground after a while. */
  let hiddenAt = 0;
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { hiddenAt = Date.now(); return; }
    if (hiddenAt && Date.now() - hiddenAt > 6 * 3600 * 1000) location.reload();
  });
})();

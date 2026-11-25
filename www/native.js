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
    },
    push: makePush()
  };
  if (!isNative) return;
  /* Safety net: never leave the splash screen up, even if the app fails to start. */
  setTimeout(() => window.hdNative.ready(), 5000);

  /* Push notifications (Firebase Cloud Messaging).
     Each phone follows "topics": town_<id>, biz_<id>, cat_<id> and "all".
     The website sends a new deal to the topics for its towns and its business. */
  function makePush() {
    const FM = P.FirebaseMessaging;
    const KEY = 'hd_topics';
    const getSubs = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
    const setSubs = a => { try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {} };
    let queue = Promise.resolve();
    const api = {
      available: isNative && !!FM,
      /* Ask permission (shows the phone's own prompt). Returns 'granted', 'denied' or 'prompt'. */
      async enable() {
        if (!api.available) return 'unavailable';
        let st = (await FM.checkPermissions()).receive;
        if (st !== 'granted') st = (await FM.requestPermissions()).receive;
        if (st === 'granted' && C.getPlatform() === 'android') {
          await FM.createChannel({ id: 'deals', name: 'New deals', description: 'New deals in your towns and from your favourite businesses', importance: 4 }).catch(() => {});
        }
        return st;
      },
      /* Follow exactly these topics: subscribe to new ones, unsubscribe from ones no longer wanted. */
      sync(wanted) {
        queue = queue.then(async () => {
          if (!api.available) return;
          const st = (await FM.checkPermissions()).receive;
          if (st !== 'granted') wanted = [];
          const have = getSubs();
          const add = wanted.filter(t => !have.includes(t));
          const drop = have.filter(t => !wanted.includes(t));
          if (!add.length && !drop.length) return;
          if (add.length) await FM.getToken().catch(() => {});   // makes sure the phone is registered first (needed on iPhone)
          const now = new Set(have);
          for (const topic of drop) { try { await FM.unsubscribeFromTopic({ topic }); now.delete(topic); } catch (e) {} }
          for (const topic of add) { try { await FM.subscribeToTopic({ topic }); now.add(topic); } catch (e) {} }
          setSubs([...now]);
        }).catch(() => {});
        return queue;
      }
    };
    if (api.available) {
      /* Tapping a notification opens the deal (or business) it is about. */
      let pending = null;
      const open = data => {
        data = data || {};
        const app = window.hdApp;
        if (!app) { pending = data; return; }
        if (data.deal) app.openDeal(data.deal);
        else if (data.biz) location.hash = 'biz-' + data.biz;
        else if (data.town) location.hash = 'town-' + data.town;
      };
      FM.addListener('notificationActionPerformed', e => open(e && e.notification && e.notification.data));
      /* If the app is open when a notification arrives, show a small message instead. */
      FM.addListener('notificationReceived', e => {
        const n = (e && e.notification) || {};
        if (window.hdApp && hdApp.toast && n.title) hdApp.toast(n.title.length > 42 ? n.title.slice(0, 40) + '…' : n.title);
      });
      const wait = setInterval(() => { if (window.hdApp && pending) { const d = pending; pending = null; open(d); } if (window.hdApp) clearInterval(wait); }, 300);
      setTimeout(() => clearInterval(wait), 20000);
    }
    return api;
  }

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

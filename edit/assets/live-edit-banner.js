const previewContext = globalThis.WYSITE_PREVIEW_CONTEXT;
// Pages built before the hint-cookie loader still include this module directly,
// so it checks the hint itself too: no sign-in hint, no request to /edit/.
const hinted = /(?:^|;\s*)wysite_editor=1/.test(document.cookie);

if (!previewContext && hinted) {
  // (The preview shell provides its own fixed admin bar.)
  const context = globalThis.WYSITE_PUBLIC_CONTEXT || {};
  const appUrl = typeof context.appUrl === "string" && context.appUrl !== "" ? context.appUrl : "/edit";

  const ensureStylesheet = () => {
    if (document.getElementById("wysite-live-banner-css")) {
      return;
    }

    const link = document.createElement("link");
    link.id = "wysite-live-banner-css";
    link.rel = "stylesheet";
    link.href = `${appUrl}/assets/live-edit-banner.css`;
    document.head.appendChild(link);
  };

  const makeLink = (className, href, text) => {
    const link = document.createElement("a");
    link.className = className;
    link.href = href;
    link.textContent = text;
    return link;
  };

  const createBanner = (payload) => {
    if (!payload.loggedIn || !payload.editUrl || document.querySelector(".wysite-live-banner")) {
      return;
    }

    ensureStylesheet();
    document.body.classList.add("wysite-live-banner-visible");

    // Built with DOM APIs and textContent: nothing from the response is parsed as HTML.
    const banner = document.createElement("div");
    banner.className = "wysite-live-banner";

    const who = document.createElement("div");
    who.textContent = `Signed in as ${payload.username || "editor"}.`;

    const actions = document.createElement("div");
    actions.className = "wysite-live-banner__actions";
    actions.append(
      makeLink("wysite-live-banner__button wysite-live-banner__button--primary", String(payload.editUrl), "Edit"),
      makeLink("wysite-live-banner__button", String(payload.dashboardUrl || `${appUrl}/index.php`), "Dashboard"),
    );

    banner.append(who, actions);
    document.body.prepend(banner);
  };

  fetch(`${appUrl}/index.php?action=session-status&path=${encodeURIComponent(window.location.pathname)}`, {
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
    },
  })
    .then((response) => (response.ok ? response.json() : null))
    .then((payload) => {
      if (payload) {
        createBanner(payload);
      }
    })
    .catch(() => {});
}

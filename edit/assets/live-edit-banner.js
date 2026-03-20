const previewContext = globalThis.WYSITE_PREVIEW_CONTEXT;
if (previewContext) {
  // The preview shell already provides its own fixed admin bar.
} else {
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

  const createBanner = (payload) => {
    if (!payload.loggedIn || !payload.editUrl || document.querySelector(".wysite-live-banner")) {
      return;
    }

    ensureStylesheet();
    document.body.classList.add("wysite-live-banner-visible");

    const banner = document.createElement("div");
    banner.className = "wysite-live-banner";
    banner.innerHTML = `
      <div>Signed in as ${payload.username || "editor"}.</div>
      <div class="wysite-live-banner__actions">
        <a class="wysite-live-banner__button wysite-live-banner__button--primary" href="${payload.editUrl}">Edit</a>
        <a class="wysite-live-banner__button" href="${payload.dashboardUrl || `${appUrl}/index.php`}">Dashboard</a>
      </div>
    `;
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

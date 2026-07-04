// Dashboard behavior, loaded as an external module so the dashboard can run under
// a strict Content-Security-Policy with no inline JavaScript.
//
// 1. Submit confirmations: any <form data-wysite-confirm="message"> asks for
//    confirmation before submitting (replaces inline onsubmit="return confirm()").
// 2. External site import: streams NDJSON progress from the importer into the
//    progress panel (moved verbatim out of an inline <script>).

(() => {
  // --- Submit confirmations (capture phase so it runs before form handlers) ---
  document.addEventListener(
    "submit",
    (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      const message = form.dataset.wysiteConfirm;
      if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopPropagation();
      }
    },
    true
  );

  // --- External site import progress stream ---
  const form = document.querySelector("[data-wysite-external-import-form]");
  const panel = document.querySelector("[data-wysite-external-import-progress]");
  if (!form || !panel || !window.fetch || !window.TextDecoder) {
    return;
  }

  const status = panel.querySelector("[data-wysite-import-progress-status]");
  const log = panel.querySelector("[data-wysite-import-progress-log]");
  const bar = panel.querySelector("[data-wysite-import-progress-bar]");
  const button = form.querySelector('button[type="submit"]');
  const defaultButtonText = button ? button.textContent : "";
  const largeAssetBytes = 5 * 1024 * 1024;

  form.addEventListener("submit", async (event) => {
    if (event.defaultPrevented) {
      return;
    }

    event.preventDefault();
    const data = new FormData(form);
    data.append("progress_stream", "1");
    panel.hidden = false;
    log.textContent = "";
    setProgress(0);
    setStatus("Starting import...");
    if (button) {
      button.disabled = true;
      button.textContent = "Importing...";
    }

    try {
      const response = await fetch(form.action, {
        method: "POST",
        credentials: "same-origin",
        body: data,
        headers: { Accept: "application/x-ndjson" },
      });
      if (!response.ok || !response.body) {
        throw new Error("Import request failed.");
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = "";
      while (true) {
        const chunk = await reader.read();
        if (chunk.done) {
          break;
        }

        buffer += decoder.decode(chunk.value, { stream: true });
        const lines = buffer.split("\n");
        buffer = lines.pop() || "";
        lines.forEach(readLine);
      }

      if (buffer.trim() !== "") {
        readLine(buffer);
      }
    } catch (error) {
      appendLog("Error: " + (error.message || "Import failed."));
      setStatus("Import stopped before it could finish.");
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = defaultButtonText;
      }
    }
  });

  function readLine(line) {
    const trimmed = line.trim();
    if (trimmed === "") {
      return;
    }

    try {
      handleEvent(JSON.parse(trimmed));
    } catch (error) {
      appendLog(trimmed);
    }
  }

  function handleEvent(event) {
    updateCounts(event);

    if (event.type === "page_start") {
      appendLog("Fetching " + event.url);
    } else if (event.type === "page_saved") {
      appendLog("Saved page " + event.path);
    } else if (event.type === "resource_saved") {
      appendLog("Saved resource " + event.path);
    } else if (event.type === "page_skipped_nonessential") {
      appendLog("Skipped non-essential " + event.url);
    } else if (event.type === "page_skipped_limit") {
      appendLog("Skipped extra page after limit " + event.url);
    } else if (event.type === "asset_start") {
      appendLog("Mirroring asset " + event.url);
    } else if (event.type === "asset_saved") {
      const size = Number(event.bytes || 0);
      appendLog((size >= largeAssetBytes ? "Mirrored large asset " : "Mirrored asset ") + event.path + (size > 0 ? " (" + formatBytes(size) + ")" : ""));
    } else if (event.type === "asset_progress") {
      const total = Number(event.total_bytes || 0);
      setStatus("Downloading " + event.url + ": " + formatBytes(Number(event.bytes || 0)) + (total > 0 ? " / " + formatBytes(total) : ""));
    } else if (event.type === "queued_asset_saved") {
      appendLog("Mirrored resource " + event.path);
    } else if (event.type === "asset_permissions_repaired") {
      appendLog("Repaired imported asset permissions: " + Number(event.files || 0) + " file(s), " + Number(event.directories || 0) + " folder(s)");
    } else if (event.type === "asset_failed") {
      appendLog("Asset failed " + event.url + ": " + event.message);
    } else if (event.type === "page_failed") {
      appendLog("Failed " + event.url + ": " + event.message);
    } else if (event.type === "complete" || event.type === "done") {
      const result = event.result || {};
      setProgress(100);
      setStatus(
        "Finished. Pages: " + count(result.saved) +
        ", resources: " + count(result.resources_saved) +
        ", assets: " + count(result.assets_saved) +
        ", /wp-content assets: " + Number(result.wp_content_assets_saved || 0) +
        ", failed: " + count(result.failed) +
        ", page-limit skipped: " + count(result.limit_skipped) +
        ", non-essential skipped: " + count(result.nonessential_skipped)
      );
    } else if (event.type === "fatal") {
      appendLog("Error: " + event.message);
      setStatus("Import failed.");
    }
  }

  function updateCounts(event) {
    if (event.type === "complete" || event.type === "done" || event.type === "fatal") {
      return;
    }
    if (!Object.prototype.hasOwnProperty.call(event, "visited") && !Object.prototype.hasOwnProperty.call(event, "max_pages")) {
      return;
    }

    const visited = Number(event.visited || 0);
    const maxPages = Number(event.max_pages || 0);
    if (maxPages > 0) {
      setProgress(Math.min(98, Math.round((visited / maxPages) * 100)));
    }

    setStatus(
      "Visited " + visited + "/" + maxPages +
      ", queued " + Number(event.queued || 0) +
      ", pages " + Number(event.saved || 0) +
      ", resources " + Number(event.resources_saved || 0) +
      ", assets " + Number(event.assets_saved || 0) +
      ", /wp-content assets " + Number(event.wp_content_assets_saved || 0) +
      ", page-limit skipped " + Number(event.skipped_limit || 0) +
      ", failed " + Number(event.failed || 0)
    );
  }

  function appendLog(message) {
    log.textContent += (log.textContent === "" ? "" : "\n") + message;
    log.scrollTop = log.scrollHeight;
  }

  function setStatus(message) {
    status.textContent = message;
  }

  function setProgress(value) {
    bar.style.width = Math.max(0, Math.min(100, value)) + "%";
  }

  function count(value) {
    return Array.isArray(value) ? value.length : 0;
  }

  function formatBytes(bytes) {
    if (bytes >= 1024 * 1024) {
      return (bytes / 1024 / 1024).toFixed(1) + " MB";
    }
    return Math.round(bytes / 1024) + " KB";
  }
})();

// --- AI assistant settings: load model list + test connection (separate IIFE so
// it runs even when the import panel above isn't present) ---
(() => {
  const form = document.querySelector('form[action*="action=save-ai-settings"]');
  if (!form || !window.fetch) return;

  const modelList = form.querySelector("[data-wysite-ai-model-list]");
  const statusEl = form.querySelector("[data-wysite-ai-status]");
  const loadBtn = form.querySelector("[data-wysite-ai-load]");
  const testBtn = form.querySelector("[data-wysite-ai-test]");

  const endpoint = (name) => form.action.split("?")[0] + "?action=" + name;
  const fieldValue = (selector) => {
    const el = form.querySelector(selector);
    return el ? el.value : "";
  };
  const payload = () => ({
    csrfToken: fieldValue('input[name="csrf_token"]'),
    provider: fieldValue('[name="provider"]'),
    base_url: fieldValue("[data-wysite-ai-baseurl]"),
    api_key: fieldValue("[data-wysite-ai-key]"),
  });

  function setStatus(message, isError) {
    if (!statusEl) return;
    statusEl.hidden = false;
    statusEl.textContent = message;
    statusEl.classList.toggle("is-error", Boolean(isError));
  }

  async function call(name) {
    const response = await fetch(endpoint(name), {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload()),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
      throw new Error(data.message || "The request failed.");
    }
    return data;
  }

  loadBtn?.addEventListener("click", async () => {
    setStatus("Loading models…");
    loadBtn.disabled = true;
    try {
      const data = await call("ai-list-models");
      const models = Array.isArray(data.models) ? data.models : [];
      if (modelList) {
        modelList.innerHTML = "";
        models.forEach((id) => {
          const option = document.createElement("option");
          option.value = id;
          modelList.appendChild(option);
        });
      }
      setStatus(
        models.length
          ? `Loaded ${models.length} model(s) — click the Model field to choose one.`
          : "The provider returned no models."
      );
    } catch (error) {
      setStatus(error.message || "Could not load models.", true);
    } finally {
      loadBtn.disabled = false;
    }
  });

  testBtn?.addEventListener("click", async () => {
    setStatus("Testing connection…");
    testBtn.disabled = true;
    try {
      const data = await call("ai-test");
      setStatus(data.message || "Connection OK.");
    } catch (error) {
      setStatus(error.message || "Connection failed.", true);
    } finally {
      testBtn.disabled = false;
    }
  });
})();

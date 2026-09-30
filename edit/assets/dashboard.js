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
    let jobId = form.dataset.resumeJob || "";
    delete form.dataset.resumeJob;
    panel.hidden = false;
    log.textContent = "";
    setProgress(0);
    setStatus("Starting import...");
    if (button) {
      button.disabled = true;
      button.textContent = "Importing...";
    }

    try {
      // The server works in short batches (WP-7): keep posting the job id back
      // until it reports the import is complete.
      let finished = false;
      let retries = 0;
      while (!finished) {
        const data = new FormData(form);
        data.append("progress_stream", "1");
        if (jobId) {
          data.append("job_id", jobId);
        }
        batchState.next = "";
        batchState.finished = false;
        batchState.job = jobId;
        batchState.progressed = false;

        let response;
        try {
          response = await fetch(form.action, {
            method: "POST",
            credentials: "same-origin",
            body: data,
            headers: { Accept: "application/x-ndjson" },
          });
          if (!response.ok || !response.body) {
            throw new Error("Import request failed (HTTP " + response.status + ").");
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
          // A host or proxy time limit can cut a long batch off mid-stream. The
          // server checkpoints the job between URLs, so resume it a few times.
          jobId = batchState.job || jobId;
          if (batchState.progressed) {
            retries = 0;
          }
          if (!jobId || retries >= maxBatchRetries) {
            throw new Error((error.message || "Import request failed.") + (jobId ? " You can resume it from the Manager." : ""));
          }
          retries++;
          appendLog("Connection lost (" + (error.message || "network error") + "); resuming in " + retries * 3 + "s (attempt " + retries + " of " + maxBatchRetries + ")...");
          await new Promise((resolve) => setTimeout(resolve, retries * 3000));
          continue;
        }

        if (batchState.next) {
          jobId = batchState.next;
          retries = 0;
        } else if (batchState.finished) {
          finished = true;
        } else if (batchState.job && (batchState.progressed || retries < maxBatchRetries)) {
          if (batchState.progressed) {
            retries = 0;
          }
          // The response ended without "batch_done" or "done": the batch was cut off.
          retries++;
          jobId = batchState.job;
          appendLog("The server stopped mid-batch; resuming in " + retries * 3 + "s (attempt " + retries + " of " + maxBatchRetries + ")...");
          await new Promise((resolve) => setTimeout(resolve, retries * 3000));
        } else {
          finished = true;
          appendLog("The import stopped early. Reload the Manager to resume it.");
          setStatus("Import stopped before it could finish.");
        }
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

  const batchState = { next: "", finished: false, job: "", progressed: false };
  const maxBatchRetries = 3;

  function handleEvent(event) {
    updateCounts(event);
    if (event.job) {
      batchState.job = event.job;
    }
    if (event.type === "page_saved" || event.type === "resource_saved" || event.type === "page_failed") {
      batchState.progressed = true;
    }
    if (event.type === "batch_done" && event.job) {
      batchState.next = event.job;
    }
    if (event.type === "complete" || event.type === "done" || event.type === "fatal") {
      batchState.finished = true;
    }

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
      if (Number(result.scripts_removed || 0) > 0) {
        appendLog(
          "Scripts removed: " + Number(result.scripts_removed) + ". Menus, tabs, toggles, sliders and animations that the original theme built with JavaScript will not work. " +
          "If you trust the source site, turn on \"Keep the original site's scripts\" in Settings and re-import with overwrite."
        );
      }
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

  const modelInput = form.querySelector("[data-wysite-ai-model]");
  const modelList = document.getElementById("wysite-ai-models");
  const taskInputs = [...form.querySelectorAll("[data-wysite-ai-task]")];
  const statusEl = form.querySelector("[data-wysite-ai-status]");
  const loadBtn = form.querySelector("[data-wysite-ai-load]");
  const testBtn = form.querySelector("[data-wysite-ai-test]");
  const suggestBtn = form.querySelector("[data-wysite-ai-suggest]");
  let loadedModels = [];

  // Model-name patterns per tier, best first. Within a pattern the provider's
  // order wins (Anthropic lists newest first).
  const tierPatterns = {
    fast: [/haiku/i, /(^|[-_.])(mini|nano|flash|lite|small)([-_.]|$)/i],
    advanced: [/fable/i, /opus/i, /^gpt-5(?!.*(mini|nano))/i, /sonnet/i, /(^|[-_.])(pro|large)([-_.]|$)/i],
  };
  const suggestFor = (tier) => {
    for (const pattern of tierPatterns[tier] || []) {
      const match = loadedModels.find((model) => pattern.test(model.id));
      if (match) return match.id;
    }
    return "";
  };

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
      loadedModels = (Array.isArray(data.models) ? data.models : []).filter((model) => model && model.id);
      if (modelList) {
        modelList.replaceChildren(
          ...loadedModels.map((model) => {
            const option = document.createElement("option");
            option.value = model.id;
            if (model.label && model.label !== model.id) option.label = model.label;
            return option;
          })
        );
      }
      if (suggestBtn) suggestBtn.hidden = loadedModels.length === 0;
      setStatus(
        loadedModels.length
          ? `Loaded ${loadedModels.length} model(s). Pick from the list in each model field, or use “Suggest models”.`
          : "The provider returned no models."
      );
    } catch (error) {
      setStatus(error.message || "Could not load models.", true);
    } finally {
      loadBtn.disabled = false;
    }
  });

  // Fill blank model fields with a suggestion for their tier; never overwrite a
  // choice the admin already made. The default gets a fast model, so only tasks
  // that need more (theme design) usually get their own.
  suggestBtn?.addEventListener("click", () => {
    const filled = [];
    if (modelInput && !modelInput.value) {
      modelInput.value = suggestFor("fast") || (loadedModels[0] ? loadedModels[0].id : "");
      if (modelInput.value) filled.push("Default model");
    }
    taskInputs.forEach((input) => {
      if (input.value) return;
      const suggestion = suggestFor(input.dataset.wysiteAiTier);
      if (suggestion && suggestion !== (modelInput ? modelInput.value : "")) {
        input.value = suggestion;
        filled.push(input.closest("label")?.querySelector("span")?.firstChild?.textContent.trim() || input.dataset.wysiteAiTask);
      }
    });
    setStatus(
      filled.length
        ? `Suggested: ${filled.join(", ")}. Review, then save.`
        : "Nothing to fill: blank tasks already use a suitable default model, or no loaded model matched their tier."
    );
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

// --- AI theme design: submit in the background so a long model call shows
// progress and a failure keeps what the admin typed (plain POST still works) ---
(() => {
  const form = document.querySelector("[data-wysite-ai-theme-form]");
  if (!form || !window.fetch || !window.FormData) return;

  const status = form.querySelector("[data-wysite-ai-theme-status]");
  const button = form.querySelector('button[type="submit"]');
  const setStatus = (message, isError) => {
    if (!status) return;
    status.hidden = false;
    status.textContent = message;
    status.classList.toggle("is-error", Boolean(isError));
  };

  form.addEventListener("submit", async (event) => {
    if (event.defaultPrevented) return;
    event.preventDefault();

    const started = Date.now();
    const tick = () => {
      const seconds = Math.round((Date.now() - started) / 1000);
      setStatus(`Designing the theme… ${seconds}s. This usually takes one to four minutes; keep this tab open.`);
    };
    tick();
    const timer = window.setInterval(tick, 1000);
    if (button) button.disabled = true;

    try {
      const response = await fetch(form.action, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: new FormData(form),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok === false) {
        throw new Error(data.message || `The theme could not be designed (HTTP ${response.status}).`);
      }
      window.location.href = data.redirect || window.location.href;
    } catch (error) {
      setStatus(error.message || "The theme could not be designed.", true);
      if (button) button.disabled = false;
    } finally {
      window.clearInterval(timer);
    }
  });
})();

// --- Managed pages filter (UX-3): text + type, client-side ---
(() => {
  document.querySelectorAll("[data-wysite-table-filter]").forEach((controls) => {
    const table = document.getElementById(controls.dataset.wysiteTableFilter);
    if (!table) return;
    const text = controls.querySelector("[data-wysite-filter-text]");
    const kind = controls.querySelector("[data-wysite-filter-kind]");
    // Large migrated sites: show 50 matching rows at a time.
    const pageSize = 50;
    let limit = pageSize;
    const more = document.createElement("button");
    more.type = "button";
    more.className = "wysite-button wysite-button--ghost";
    more.hidden = true;
    table.closest(".wysite-table-wrap")?.insertAdjacentElement("afterend", more);
    more.addEventListener("click", () => {
      limit += pageSize;
      apply();
    });

    const apply = () => {
      const needle = (text?.value || "").trim().toLowerCase();
      const wanted = kind?.value || "";
      let matched = 0;
      table.querySelectorAll("tbody tr").forEach((row) => {
        const matchesText = needle === "" || (row.dataset.search || "").includes(needle);
        const matchesKind = wanted === "" || row.dataset.kind === wanted;
        const matches = matchesText && matchesKind;
        if (matches) {
          matched++;
        }
        row.hidden = !matches || matched > limit;
      });
      more.hidden = matched <= limit;
      more.textContent = `Show more (${matched - Math.min(limit, matched)} hidden)`;
    };
    text?.addEventListener("input", () => {
      limit = pageSize;
      apply();
    });
    kind?.addEventListener("change", () => {
      limit = pageSize;
      apply();
    });
    apply();
  });
})();

// --- Live URL preview for create/move forms (UX-10) ---
// Mirrors SiteGenerator::normalizeSlug(): lowercase, [a-z0-9/-], and only a
// first segment of exactly "edit" or "assets" is reserved.
(() => {
  const normalize = (value) => value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9/-]+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^[-/]+|[-/]+$/g, "");

  document.querySelectorAll("[data-wysite-slug-preview]").forEach((slugInput) => {
    const form = slugInput.closest("form");
    const source = form?.querySelector("[data-wysite-slug-source]");
    const preview = form?.querySelector("[data-wysite-url-preview]");
    const base = slugInput.dataset.wysiteSlugPreview || "/";
    const permalink = slugInput.dataset.wysitePermalink || "";
    const update = () => {
      if (!preview) return;
      let slug = normalize(slugInput.value || source?.value || "");
      if (permalink) {
        const now = new Date();
        slug = permalink
          .replace("{slug}", slug.replace(/\//g, "-"))
          .replace("{yyyy}", String(now.getUTCFullYear()))
          .replace("{mm}", String(now.getUTCMonth() + 1).padStart(2, "0"));
      }
      if (!slug) {
        preview.hidden = true;
        return;
      }
      preview.hidden = false;
      if (/^(edit|assets)(\/|$)/.test(slug)) {
        preview.textContent = `"${slug.split("/")[0]}/" is reserved for the editor and uploads — choose another slug.`;
        preview.classList.add("is-error");
      } else {
        preview.textContent = `Address: ${base}${slug === "index" ? "" : slug + "/"}`;
        preview.classList.remove("is-error");
      }
    };
    slugInput.addEventListener("input", update);
    source?.addEventListener("input", update);
    update();
  });
})();

// --- AI template-kind suggestions, loaded after render (UX-7) ---
(() => {
  const status = document.querySelector("[data-wysite-ai-kinds-url]");
  if (!status || !window.fetch) return;
  status.hidden = false;
  fetch(status.dataset.wysiteAiKindsUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
    .then((response) => (response.ok ? response.json() : null))
    .then((data) => {
      const kinds = (data && data.kinds) || {};
      let applied = 0;
      document.querySelectorAll("[data-wysite-ai-kind]").forEach((select) => {
        const kind = kinds[select.dataset.wysiteAiKind];
        if (kind && select.querySelector(`option[value="${kind}"]`)) {
          select.value = kind;
          applied++;
        }
      });
      status.textContent = applied > 0 ? `AI suggested template kinds for ${applied} page(s).` : "No AI suggestions available.";
    })
    .catch(() => {
      status.textContent = "AI suggestions unavailable.";
    });
})();

// --- Resume an unfinished crawl (WP-7) ---
(() => {
  document.querySelectorAll("[data-wysite-resume-job]").forEach((button) => {
    button.addEventListener("click", () => {
      const form = document.querySelector("[data-wysite-external-import-form]");
      if (!form) return;
      const url = form.querySelector('input[name="url"]');
      if (url) url.value = button.dataset.wysiteResumeUrl || url.value;
      form.dataset.resumeJob = button.dataset.wysiteResumeJob;
      form.requestSubmit();
    });
  });
})();

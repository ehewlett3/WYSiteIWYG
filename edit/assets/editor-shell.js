import { DropInWysiwyg } from "./dropin-wysiwyg.js";

const context = window.WYSITE_PREVIEW_CONTEXT || null;

if (context) {
  document.body.classList.add("wysite-preview-mode");

  const toast = createToast();
  let currentInstance = null;
  // Synchronous lock so a second click (e.g. tapping the same badge again, or
  // before mount() resolves) can never mount a second editor into a slot that
  // already hosts one — which would serialize the editor's own DOM on save.
  let opening = false;

  setupBlockBadges();
  setupSelectorMode();

  function setupBlockBadges() {
    document.querySelectorAll(".wysite-edit-button").forEach((button) => {
      button.addEventListener("click", async () => {
        try {
          const blockName = button.dataset.wysiteBlock;
          const blockType = button.dataset.wysiteType;

          if (!blockName || !blockType) return;

          const slot = document.querySelector(
            `[data-wysite-edit-slot="${cssEscape(blockName)}"]`
          );
          if (!slot || slot.__pmDropInMounted) return;

          await openManagedBlockEditor(slot, slot, blockName, blockType, true);
        } catch (error) {
          toast(error.message || "The editor could not be opened.", true);
        }
      });
    });
  }

  async function openManagedBlockEditor(target, slot, blockName, blockType, useFrameAdapter = false) {
    if (currentInstance || opening) {
      if (currentInstance && currentInstance.target !== target) {
        toast("Finish or cancel the current editor first.", true);
      }
      return;
    }

    opening = true;
    try {
      const frameAdapter = useFrameAdapter ? createFrameAdapter(slot, blockType) : null;
      if (frameAdapter) {
        slot.innerHTML = frameAdapter.initialHTML;
      }

      currentInstance = await DropInWysiwyg.mount(target, {
        initialHTML: frameAdapter?.initialHTML,
        restoreHTML: frameAdapter?.restoreHTML ?? target.innerHTML,
        rootClassName: blockType === "menu" ? "pm-dropin--menu" : "",
        rootStyle: blockType === "menu" ? getMenuEditorStyle(slot) : null,
        mountAfterTarget: blockType === "menu" ? getMenuMountAnchor(slot) : null,
        uploadImage: uploadImageFile,
        linkSuggestions: loadPageIndex,
        onError: (message) => {
          toast(message || "The editor action failed.", true);
        },
        onSave: async ({ html, target: savedTarget }) => {
          try {
            if (frameAdapter) {
              const wrappedHtml = frameAdapter.wrap(html);
              if (savedTarget) {
                savedTarget.innerHTML = wrappedHtml;
              }
            }

            const payload = await saveBlock(blockName, slot.innerHTML);
            toast(payload.message || "Saved.");
            currentInstance = null;
          } catch (error) {
            toast(error.message || "Save failed.", true);
            throw error;
          }
        },
        onCancel: async () => {
          currentInstance = null;
        },
      });
    } finally {
      opening = false;
    }
  }

  async function openSelectedElementEditor(target) {
    if (currentInstance || opening) {
      if (currentInstance && currentInstance.target !== target) {
        toast("Finish or cancel the current editor first.", true);
      }
      return;
    }

    const slot = target.closest("[data-wysite-edit-slot]");
    if (slot) {
      const blockName = slot.dataset.wysiteEditSlot;
      const region = slot.closest(".wysite-edit-region");
      const blockType = region?.dataset.wysiteType || "page";

      if (!blockName) {
        toast("That managed block could not be identified.", true);
        return;
      }

      const blockInfo = (context.blocks || []).find((block) => block.name === blockName);
      if (blockInfo?.adminOnly && !context.isAdmin) {
        toast("This block holds raw HTML and is admin-only.", true);
        return;
      }

      await openManagedBlockEditor(target, slot, blockName, blockType, target === slot);
      return;
    }

    if (context.selectionSaveEnabled === false) {
      toast("Selector edits are disabled while previewing an unapplied theme.", true);
      return;
    }

    const domPath = buildDomPath(target);
    const scope = defaultScopeForElement(target);
    if (scope === "template" && !context.isAdmin) {
      toast("Template sections are admin-only.", true);
      return;
    }
    if (scope === "template" && !confirmTemplateEdit(target)) {
      return;
    }

    opening = true;
    try {
      currentInstance = await DropInWysiwyg.mount(target, {
        restoreHTML: target.innerHTML,
        uploadImage: uploadImageFile,
        linkSuggestions: loadPageIndex,
        onError: (message) => {
          toast(message || "The editor action failed.", true);
        },
        onSave: async ({ html }) => {
          try {
            const payload = await postJson(
              `${context.appUrl}/index.php?action=save-selection`,
              {
                csrfToken: context.csrfToken,
                path: context.pagePath,
                kind: context.pageKind || "page",
                scope,
                domPath,
                html,
                baseHash: scope === "page" ? context.fileHash || "" : "",
              }
            );
            rememberFileHash(payload);

            toast(payload.message || "Saved.");
            currentInstance = null;
          } catch (error) {
            toast(error.message || "Save failed.", true);
            throw error;
          }
        },
        onCancel: async () => {
          currentInstance = null;
        },
      });
    } finally {
      opening = false;
    }
  }

  function setupSelectorMode() {
    const actions = document.querySelector(".wysite-admin-bar__actions");
    if (!actions) return;

    const button = document.createElement("button");
    button.type = "button";
    button.className = "wysite-admin-link wysite-admin-link--button";
    button.textContent = "Select section";
    actions.prepend(button);

    const label = document.createElement("div");
    label.className = "wysite-selector-label";
    label.hidden = true;
    document.body.appendChild(label);

    let active = false;
    let highlighted = null;

    button.addEventListener("click", () => {
      if (currentInstance) {
        toast("Finish or cancel the current editor first.", true);
        return;
      }

      setActive(!active);
    });

    document.addEventListener("mousemove", (event) => {
      if (!active) return;

      const target = resolveSelectableTarget(event.target);
      if (target !== highlighted) {
        clearHighlight();
        highlighted = target;
        if (highlighted) {
          highlighted.classList.add("wysite-selector-highlight");
          label.innerHTML = describeTarget(highlighted);
          label.hidden = false;
        } else {
          label.hidden = true;
        }
      }

      if (highlighted) {
        positionLabel(label, event.clientX, event.clientY);
      }
    }, true);

    document.addEventListener("click", (event) => {
      if (!active) return;

      const target = resolveSelectableTarget(event.target);
      if (!target) return;

      event.preventDefault();
      event.stopPropagation();
      setActive(false);
      openSelectedElementEditor(target).catch((error) => {
        toast(error.message || "The editor could not be opened.", true);
      });
    }, true);

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && active) {
        setActive(false);
      }
    });

    function setActive(next) {
      active = next;
      document.body.classList.toggle("wysite-selector-active", active);
      button.textContent = active ? "Cancel select" : "Select section";

      if (!active) {
        clearHighlight();
        label.hidden = true;
      }
    }

    function clearHighlight() {
      if (highlighted) {
        highlighted.classList.remove("wysite-selector-highlight");
        highlighted = null;
      }
    }
  }

  async function saveBlock(blockName, html) {
    const payload = await postJson(
      `${context.appUrl}/index.php?action=save-block`,
      {
        csrfToken: context.csrfToken,
        path: context.pagePath,
        name: blockName,
        html,
        baseHash: context.fileHash || "",
      }
    );
    rememberFileHash(payload);
    return payload;
  }

  // Pages for the editor's link picker (NAV-1), fetched once per preview.
  let pageIndexPromise = null;
  function loadPageIndex() {
    pageIndexPromise ??= fetchJson(`${context.appUrl}/index.php?action=pages-index`).then((data) => data.pages || []);
    return pageIndexPromise;
  }

  // Track the saved file's hash so the next save can detect a concurrent edit.
  function rememberFileHash(payload) {
    if (payload && typeof payload.fileHash === "string") {
      context.fileHash = payload.fileHash;
    }
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        ...(options.headers || {}),
      },
      ...options,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
      throw new Error(data.message || "The request failed.");
    }

    return data;
  }

  function postJson(url, body) {
    return fetchJson(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(body),
    });
  }

  async function uploadImageFile(file) {
    const form = new FormData();
    form.append("csrf_token", context.csrfToken);
    form.append("image", file, file.name || "image");

    const response = await fetch(`${context.appUrl}/index.php?action=upload-image`, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
      },
      body: form,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false || typeof data.url !== "string" || data.url === "") {
      throw new Error(data.message || "Image upload failed.");
    }

    return data.url;
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(value);
    }

    return value.replace(/"/g, '\\"');
  }

  function createToast() {
    const el = document.createElement("div");
    el.className = "wysite-toast";
    document.body.appendChild(el);

    let timeoutId = 0;

    return (message, isError = false) => {
      el.textContent = message;
      el.classList.toggle("is-error", isError);
      el.classList.add("is-visible");
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => {
        el.classList.remove("is-visible");
      }, 3200);
    };
  }

  function resolveSelectableTarget(node) {
    if (!(node instanceof Element) || isPreviewChrome(node)) {
      return null;
    }

    const slot = node.closest("[data-wysite-edit-slot]");
    if (slot && !isPreviewChrome(slot)) {
      const selected = closestEditableContainer(node, slot);
      return selected && slot.contains(selected) ? selected : slot;
    }

    if (node.closest(".wysite-edit-region")) {
      return null;
    }

    const selected = closestEditableContainer(node, document.body);
    if (!selected || selected === document.body || selected === document.documentElement) {
      return null;
    }

    if (isPreviewChrome(selected) || selected.closest(".wysite-edit-region")) {
      return null;
    }

    return selected;
  }

  function closestEditableContainer(node, boundary) {
    const selector = "section, article, aside, nav, header, footer, main, div, ul, ol, table, blockquote, figure";
    let current = node instanceof Element ? node : node.parentElement;

    while (current && current !== boundary.parentElement) {
      if (current.matches?.(selector) && !isPreviewChrome(current)) {
        return current;
      }

      if (current === boundary) {
        return boundary instanceof Element ? boundary : null;
      }

      current = current.parentElement;
    }

    return null;
  }

  function isPreviewChrome(element) {
    return Boolean(
      element.closest(
        ".wysite-admin-bar, .wysite-toast, .wysite-selector-label, .pm-dropin"
      )
      || element.classList.contains("wysite-edit-button")
    );
  }

  function isInjectedPreviewElement(element) {
    return Boolean(
      element.matches?.(".wysite-admin-bar, .wysite-toast, .wysite-selector-label, .pm-dropin")
    );
  }

  function buildDomPath(element) {
    const path = [];
    let current = element;

    while (current && current.nodeType === Node.ELEMENT_NODE) {
      const tag = current.tagName.toLowerCase();
      let index = 1;
      let sibling = current.previousElementSibling;

      while (sibling) {
        if (!isInjectedPreviewElement(sibling) && sibling.tagName.toLowerCase() === tag) {
          index += 1;
        }
        sibling = sibling.previousElementSibling;
      }

      path.unshift({ tag, index });
      if (tag === "html") break;
      current = current.parentElement;
    }

    return path;
  }

  function defaultScopeForElement(element) {
    return element.closest("[data-wysite-edit-slot]") ? "page" : "template";
  }

  function confirmTemplateEdit(element) {
    const templatePath = context.activeTemplatePath || "the active template";
    const pageKind = context.pageKind || "page";
    const label = element.tagName.toLowerCase();

    return window.confirm(
      `You selected a ${label} that belongs to ${templatePath}.\n\n` +
      `Saving this edit will update the active ${pageKind} template and rebuild every page that uses that template.\n\n` +
      "Continue?"
    );
  }

  function describeTarget(element) {
    const tag = element.tagName.toLowerCase();
    const id = element.id ? `#${escapeHtml(element.id)}` : "";
    const classes = element.classList.length
      ? "." + Array.from(element.classList)
        .filter((name) => name !== "wysite-selector-highlight")
        .map(escapeHtml)
        .join(".")
      : "";
    const rect = element.getBoundingClientRect();
    const scope = element.closest("[data-wysite-edit-slot]")
      ? "Managed block"
      : defaultScopeForElement(element) === "template"
        ? context.isAdmin
          ? `Active template (${escapeHtml(context.activeTemplatePath || "template")})`
          : "Template section (admin-only)"
        : "This page section";

    return `
      <div><strong>${escapeHtml(tag)}</strong>${id ? ` <span>${id}</span>` : ""}${classes ? ` <span>${classes}</span>` : ""}</div>
      <div>${Math.round(rect.width)} x ${Math.round(rect.height)} · ${scope}</div>
      <div>${defaultScopeForElement(element) === "template" && !context.isAdmin && !element.closest("[data-wysite-edit-slot]") ? "Template sections are admin-only" : "Click to edit this section"}</div>
    `;
  }

  function positionLabel(label, x, y) {
    const padding = 12;
    const offset = 14;
    let nextX = x + offset;
    let nextY = y + offset;

    label.style.left = "0";
    label.style.top = "0";
    label.style.transform = `translate(${nextX}px, ${nextY}px)`;

    const rect = label.getBoundingClientRect();
    if (nextX + rect.width + padding > window.innerWidth) {
      nextX = x - rect.width - offset;
    }
    if (nextY + rect.height + padding > window.innerHeight) {
      nextY = y - rect.height - offset;
    }

    label.style.transform = `translate(${Math.max(padding, nextX)}px, ${Math.max(padding, nextY)}px)`;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function getMenuEditorStyle(slot) {
    const surface = slot.closest(".site-header") || slot.closest("header") || slot.parentElement || slot;
    const computed = window.getComputedStyle(surface);
    const color = window.getComputedStyle(slot).color || computed.color;

    return {
      "--pm-menu-bg-color": computed.backgroundColor || "rgba(18, 40, 31, 0.92)",
      "--pm-menu-bg-image": computed.backgroundImage && computed.backgroundImage !== "none"
        ? computed.backgroundImage
        : "none",
      "--pm-menu-ink": color || "inherit",
    };
  }

  function getMenuMountAnchor(slot) {
    return slot.closest(".site-header") || slot.closest("header") || slot;
  }

  function createFrameAdapter(slot, blockType) {
    if (!slot || blockType === "menu") {
      return null;
    }

    const scratch = document.createElement("div");
    const originalHtml = slot.innerHTML;
    scratch.innerHTML = originalHtml;

    const topLevelNodes = Array.from(scratch.childNodes).filter((node) => {
      return node.nodeType !== Node.TEXT_NODE || node.textContent.trim() !== "";
    });

    if (topLevelNodes.length !== 1 || topLevelNodes[0].nodeType !== Node.ELEMENT_NODE) {
      return null;
    }

    const outer = topLevelNodes[0];
    if (!outer.matches(".content-block, .blog-card")) {
      return null;
    }

    const outerChildren = Array.from(outer.childNodes).filter((node) => {
      return node.nodeType !== Node.TEXT_NODE || node.textContent.trim() !== "";
    });

    const outerTemplate = outer.cloneNode(false);
    const inner = outerChildren.length === 1 && outerChildren[0].nodeType === Node.ELEMENT_NODE
      ? outerChildren[0]
      : null;

    if (inner && inner.matches(".content-stack, .post-body")) {
      const innerTemplate = inner.cloneNode(false);
      return {
        initialHTML: inner.innerHTML,
        restoreHTML: originalHtml,
        wrap(html) {
          const outerClone = outerTemplate.cloneNode(false);
          const innerClone = innerTemplate.cloneNode(false);
          innerClone.innerHTML = html;
          outerClone.appendChild(innerClone);
          return outerClone.outerHTML;
        },
      };
    }

    return {
      initialHTML: outer.innerHTML,
      restoreHTML: originalHtml,
      wrap(html) {
        const outerClone = outerTemplate.cloneNode(false);
        outerClone.innerHTML = html;
        return outerClone.outerHTML;
      },
    };
  }
}

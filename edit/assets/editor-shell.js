import { DropInWysiwyg } from "./dropin-wysiwyg.js";

const context = window.WYSITE_PREVIEW_CONTEXT || null;

if (context) {
  document.body.classList.add("wysite-preview-mode");

  const toast = createToast();
  let currentInstance = null;

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

        if (currentInstance && currentInstance.target !== slot) {
          toast("Finish or cancel the current editor first.", true);
          return;
        }

        const frameAdapter = createFrameAdapter(slot, blockType);
        if (frameAdapter) {
          slot.innerHTML = frameAdapter.initialHTML;
        }

        currentInstance = await DropInWysiwyg.mount(slot, {
          initialHTML: frameAdapter?.initialHTML,
          restoreHTML: frameAdapter?.restoreHTML,
          rootClassName: blockType === "menu" ? "pm-dropin--menu" : "",
          rootStyle: blockType === "menu" ? getMenuEditorStyle(slot) : null,
          mountAfterTarget: blockType === "menu" ? getMenuMountAnchor(slot) : null,
          uploadImage: uploadImageFile,
          onError: (message) => {
            toast(message || "The editor action failed.", true);
          },
          onSave: async ({ html, target }) => {
            try {
              const wrappedHtml = frameAdapter ? frameAdapter.wrap(html) : html;
              if (target) {
                target.innerHTML = wrappedHtml;
              }

              const payload = await postJson(
                `${context.appUrl}/index.php?action=save-block`,
                {
                  csrfToken: context.csrfToken,
                  path: context.pagePath,
                  name: blockName,
                  html: wrappedHtml,
                }
              );

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
      } catch (error) {
        toast(error.message || "The editor could not be opened.", true);
      }
    });
  });

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

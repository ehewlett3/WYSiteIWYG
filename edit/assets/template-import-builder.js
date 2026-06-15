const context = globalThis.WYSITE_TEMPLATE_IMPORT_CONTEXT || null;

if (context) {
  document.body.classList.add("wysite-template-import-mode");

  const roleSelect = document.getElementById("wysite-template-role");
  const saveButton = document.getElementById("wysite-template-save");
  const list = document.getElementById("wysite-template-selection-list");
  const selections = {};
  const highlightedByRole = new Map();
  let hoverTarget = null;

  initRoleSelect();
  renderSelections();

  document.addEventListener("mousemove", (event) => {
    const target = selectableTarget(event.target);
    if (target === hoverTarget) return;

    if (hoverTarget) hoverTarget.classList.remove("wysite-template-hover");
    hoverTarget = target;
    if (hoverTarget) hoverTarget.classList.add("wysite-template-hover");
  }, true);

  document.addEventListener("click", (event) => {
    const target = selectableTarget(event.target);
    if (!target) return;

    event.preventDefault();
    event.stopPropagation();
    selectElementForRole(roleSelect.value, target);
  }, true);

  saveButton?.addEventListener("click", async () => {
    const missing = context.roles.filter((role) => role.required && !selections[role.id]);
    if (missing.length) {
      alert(`Select these required sections first:\n\n${missing.map((role) => `- ${role.label}`).join("\n")}`);
      return;
    }

    const ok = window.confirm(
      `Create the active ${context.kind} template from these selections?\n\n` +
      "This will replace the matching file in /edit/templates/ and rebuild pages that use it."
    );
    if (!ok) return;

    saveButton.disabled = true;
    saveButton.textContent = "Saving...";

    try {
      const response = await fetch(`${context.appUrl}/index.php?action=external-template-save`, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          csrfToken: context.csrfToken,
          importId: context.importId,
          kind: context.kind,
          selections,
        }),
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(payload.message || "Template save failed.");
      }

      alert(payload.message || "Template saved.");
      window.location.href = `${context.appUrl}/index.php`;
    } catch (error) {
      alert(error.message || "Template save failed.");
      saveButton.disabled = false;
      saveButton.textContent = "Save template";
    }
  });

  function initRoleSelect() {
    if (!roleSelect) return;

    roleSelect.innerHTML = "";
    context.roles.forEach((role) => {
      const option = document.createElement("option");
      option.value = role.id;
      option.textContent = `${role.label}${role.required ? " *" : ""}`;
      roleSelect.appendChild(option);
    });
  }

  function selectElementForRole(roleId, element) {
    const previous = highlightedByRole.get(roleId);
    if (previous) {
      previous.classList.remove("wysite-template-selected");
      previous.removeAttribute("data-wysite-template-role");
    }

    selections[roleId] = buildDomPath(element);
    highlightedByRole.set(roleId, element);
    element.classList.add("wysite-template-selected");
    element.dataset.wysiteTemplateRole = roleLabel(roleId);
    renderSelections();
  }

  function renderSelections() {
    if (!list) return;

    list.innerHTML = context.roles.map((role) => {
      const selected = Boolean(selections[role.id]);
      return `
        <span class="${selected ? "is-selected" : ""}">
          ${escapeHtml(role.label)}${role.required ? " *" : ""}: ${selected ? "selected" : "not selected"}
        </span>
      `;
    }).join("");
  }

  function selectableTarget(node) {
    if (!(node instanceof Element) || isImporterChrome(node)) {
      return null;
    }

    const selector = "nav, ul, ol, main, section, article, aside, header, footer, div, table";
    let current = node;
    while (current && current !== document.body && current !== document.documentElement) {
      if (current.matches(selector) && !isImporterChrome(current)) {
        return current;
      }
      current = current.parentElement;
    }

    return null;
  }

  function isImporterChrome(element) {
    return Boolean(element.closest(".wysite-template-import-bar, .wysite-template-selection-list"));
  }

  function buildDomPath(element) {
    const path = [];
    let current = element;

    while (current && current.nodeType === Node.ELEMENT_NODE) {
      const tag = current.tagName.toLowerCase();
      let index = 1;
      let sibling = current.previousElementSibling;
      while (sibling) {
        if (!isInjectedElement(sibling) && sibling.tagName.toLowerCase() === tag) {
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

  function isInjectedElement(element) {
    return element.matches(".wysite-template-import-bar, .wysite-template-selection-list");
  }

  function roleLabel(roleId) {
    return context.roles.find((role) => role.id === roleId)?.label || roleId;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }
}

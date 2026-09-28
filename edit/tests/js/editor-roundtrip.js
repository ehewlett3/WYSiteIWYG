// Browser test for DATA-2: run with headless Chrome (see edit/tests/README.md).
import { buildSchema, createDocument, serializeNodes, serializeDocument, schemaLoss } from "../../assets/html-fidelity.js";
import { DropInWysiwyg } from "../../assets/dropin-wysiwyg.js";

const FIXTURES = {
  gutenberg: `<figure class="wp-block-image size-large"><img decoding="async" width="1024" height="683" src="assets/imported/x/photo.jpg" alt="A photo" class="wp-image-12" srcset="assets/a.jpg 1024w, assets/b.jpg 300w" sizes="(max-width: 1024px) 100vw, 1024px" loading="lazy"><figcaption class="wp-element-caption">Caption <em>here</em></figcaption></figure>`
    + `<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper"><iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/abc?feature=oembed" frameborder="0" allow="accelerometer; autoplay" allowfullscreen=""></iframe></div></figure>`
    + `<figure class="wp-block-audio"><audio controls="" src="assets/imported/x/sermon.mp3"></audio></figure>`
    + `<dl class="terms"><dt>Term</dt><dd>Definition with <a href="https://ex.com/" target="_blank" rel="noopener">a link</a></dd></dl>`,
  inline: `<p class="lead" data-x="1">Some <b>bold</b>, <i>italic</i>, <strong>strong</strong>, H<sub>2</sub>O, x<sup>2</sup>, <u>under</u>, <s>struck</s>, <del>del</del> <ins>ins</ins>, <mark>mark</mark>, <small>small</small>, <abbr title="HyperText">HTML</abbr>, <span style="color: red">red</span>, <code>code()</code>, <kbd>Ctrl</kbd>.</p>`,
  containers: `<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="contact/">Contact us</a></div></div><section id="s1" aria-label="Intro"><h2 id="h">Heading</h2><p>Para</p></section><header class="x">Header text</header>`,
  lists: `<ul class="list"><li>One</li><li>Two<ul><li>Nested</li></ul></li></ul><ol start="3" type="a"><li value="3">Three</li></ol>`,
  table: `<table class="wp-table"><caption>Cap</caption><thead><tr><th scope="col">A</th><th>B</th></tr></thead><tbody><tr><td colspan="2">Wide</td></tr></tbody><tfoot><tr><td>F1</td><td>F2</td></tr></tfoot></table>`,
  raw: `<details><summary>More</summary><p>Hidden</p></details><form action="https://forms.ex.com/x" method="post"><input name="email" type="email"><button type="submit">Go</button></form><p>Text with <iframe src="https://player.vimeo.com/video/1"></iframe> inline embed.</p><my-widget data-id="7">custom</my-widget><picture><source srcset="a.webp" type="image/webp"><img src="a.jpg" alt=""></picture>`,
  code: `<pre class="wp-block-code"><code class="language-js">const a = 1;\n  indented();</code></pre><pre>plain pre</pre><hr class="sep"><blockquote class="q"><p>Quote</p><cite>Someone</cite></blockquote>`,
  bare: `Just text at the top level with <a href="x/">a link</a>.`,
};

function normalize(html) {
  const t = document.createElement("template");
  t.innerHTML = html;
  return t.innerHTML;
}

const results = { pass: 0, fail: 0, failures: [] };
function check(name, ok, detail) {
  if (ok) {
    results.pass++;
  } else {
    results.fail++;
    results.failures.push({ name, detail });
  }
}

const schema = buildSchema();

for (const [name, html] of Object.entries(FIXTURES)) {
  const expected = normalize(html);
  const { doc, segments } = createDocument(schema, expected);

  // 1. Full regeneration from the schema (no verbatim reuse) reproduces the markup.
  const nodes = [];
  doc.content.forEach((n) => nodes.push(n));
  const regenerated = serializeNodes(schema, nodes);
  check(`${name}: schema round-trip`, regenerated === expected, { expected, regenerated });
  check(`${name}: no schema loss`, schemaLoss(schema, segments).length === 0, schemaLoss(schema, segments));

  // 2. Unchanged document serializes back exactly.
  check(`${name}: unchanged document`, serializeDocument(schema, doc, segments).html === expected, serializeDocument(schema, doc, segments).html);
}

// 3. Mount the real editor, edit only the first block, save: everything else is byte-identical.
async function editFirstBlock(html) {
  const stage = document.getElementById("stage");
  stage.innerHTML = html;
  const before = stage.innerHTML;
  const instance = await DropInWysiwyg.mount(stage, { onSave: async () => {} });
  const { view } = instance;
  view.dispatch(view.state.tr.insertText("EDITED ", 2));
  await instance.save();
  return { before, after: stage.innerHTML };
}

const combined = normalize(FIXTURES.inline + FIXTURES.gutenberg + FIXTURES.table + FIXTURES.raw);
const { before, after } = await editFirstBlock(combined);
const expectedAfter = before.replace('<p class="lead" data-x="1">S', '<p class="lead" data-x="1">SEDITED ');
check("mounted edit touches only the edited block", after === expectedAfter, { before, after });

// 4. Unedited mount + save is a no-op.
{
  const stage = document.getElementById("stage");
  stage.innerHTML = combined;
  const original = stage.innerHTML;
  const instance = await DropInWysiwyg.mount(stage, { onSave: async () => {} });
  check("unedited editor is clean", instance.isDirty() === false, null);
  await instance.save();
  check("unedited save is byte-identical", stage.innerHTML === original, stage.innerHTML);
}

// 5. Editing next to implicit paragraphs: a split or a new paragraph becomes real <p>s,
//    untouched siblings stay as they were.
async function mountAndEdit(html, edit) {
  const stage = document.getElementById("stage");
  stage.innerHTML = html;
  const instance = await DropInWysiwyg.mount(stage, { onSave: async () => {} });
  edit(instance.view);
  await instance.save();
  return stage.innerHTML;
}
{
  // <div>Hello world</div>: positions 0=before div, 1=before implicit p, 2=text start.
  const split = await mountAndEdit('<div class="box">Hello world</div>', (view) => view.dispatch(view.state.tr.split(7)));
  check("splitting an implicit paragraph makes two real paragraphs", split === '<div class="box"><p>Hello</p><p> world</p></div>', split);

  const typed = await mountAndEdit('<div class="box">Hello</div><p>After</p>', (view) => view.dispatch(view.state.tr.insertText("!", 7)));
  check("typing in an implicit paragraph keeps it implicit", typed === '<div class="box">Hello!</div><p>After</p>', typed);

  const quote = await mountAndEdit('<blockquote><p>Quote</p><cite>Someone</cite></blockquote>', (view) => view.dispatch(view.state.tr.insertText("d", 7)));
  check("editing a quote keeps its bare <cite>", quote === '<blockquote><p>Quoted</p><cite>Someone</cite></blockquote>', quote);
}

// 6. Link picker: choosing a page by title links the selection to its URL.
{
  const stage = document.getElementById("stage");
  stage.innerHTML = "<p>Read about us</p>";
  const instance = await DropInWysiwyg.mount(stage, {
    onSave: async () => {},
    linkSuggestions: async () => [{ title: "About", url: "about/" }],
  });
  const { view } = instance;
  const { TextSelection } = await import("../../assets/vendor/prosemirror-state.js");
  view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, 6, 14)));
  instance.root.querySelector('button[title="Add/remove link"]').click();
  const dialog = instance.root.querySelector(".pm-link-dialog");
  await new Promise((resolve) => setTimeout(resolve, 0));
  const input = dialog.querySelector("input[type=text]");
  input.value = "About";
  dialog.querySelector(".pm-btn--save").click();
  await instance.save();
  check("link picker links the selection to the chosen page", stage.innerHTML === '<p>Read <a href="about/">about us</a></p>', stage.innerHTML);
}

document.getElementById("results").textContent = JSON.stringify(results, null, 2);
document.title = results.fail === 0 ? "PASS" : "FAIL";

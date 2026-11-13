/**
 * Remove upload clears the chosen file. It does not open the file picker.
 */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import vm from "node:vm";

const root = process.cwd();
const scriptPath = path.join(root, "assets/definition-form.js");

function classList() {
  const names = new Set();
  return {
    add(...items) {
      items.forEach((item) => names.add(item));
    },
    remove(...items) {
      items.forEach((item) => names.delete(item));
    },
    toggle(item, force) {
      const on = force === undefined ? !names.has(item) : force;
      if (on) {
        names.add(item);
      } else {
        names.delete(item);
      }
      return on;
    },
    contains(item) {
      return names.has(item);
    },
  };
}

function element(tag, attrs = {}) {
  const node = {
    tagName: tag.toUpperCase(),
    attrs: { ...attrs },
    children: [],
    listeners: {},
    hidden: false,
    textContent: "",
    style: {},
    classList: classList(),
    parentNode: null,
    files: attrs.files || null,
    _value: attrs.value || "",
    clickCount: 0,
  };
  Object.defineProperty(node, "value", {
    get() {
      return node._value;
    },
    set(next) {
      node._value = String(next);
      if (node.tagName === "INPUT" && node._value === "") {
        node.files = null;
      }
    },
  });
  node.setAttribute = (name, value) => {
    node.attrs[name] = value;
  };
  node.getAttribute = (name) => (name in node.attrs ? node.attrs[name] : null);
  node.removeAttribute = (name) => {
    delete node.attrs[name];
  };
  node.addEventListener = (type, fn) => {
    node.listeners[type] = node.listeners[type] || [];
    node.listeners[type].push(fn);
  };
  node.dispatchEvent = (event) => {
    const list = node.listeners[event.type] || [];
    list.forEach((fn) => fn(event));
  };
  node.click = () => {
    node.clickCount += 1;
    node.dispatchEvent({ type: "click", target: node, preventDefault() {} });
  };
  node.querySelector = (selector) => node.querySelectorAll(selector)[0] || null;
  node.querySelectorAll = (selector) => {
    const out = [];
    const walk = (current) => {
      current.children.forEach((child) => {
        if (matches(child, selector)) {
          out.push(child);
        }
        walk(child);
      });
    };
    walk(node);
    return out;
  };
  return node;
}

function matches(node, selector) {
  if (selector.startsWith(".")) {
    return node.classList.contains(selector.slice(1));
  }
  if (selector.startsWith("[") && selector.endsWith("]")) {
    const body = selector.slice(1, -1);
    const eq = body.indexOf("=");
    if (eq === -1) {
      return body in node.attrs;
    }
    const name = body.slice(0, eq);
    const value = body.slice(eq + 1).replace(/^"|"$/g, "");
    return node.attrs[name] === value;
  }
  const tagEnd = selector.indexOf("[");
  const tag = tagEnd === -1 ? selector : selector.slice(0, tagEnd);
  if (tag && node.tagName !== tag.toUpperCase()) {
    return false;
  }
  if (tagEnd === -1) {
    return true;
  }
  return matches(node, selector.slice(tagEnd));
}

function append(parent, child) {
  child.parentNode = parent;
  parent.children.push(child);
  return child;
}

test("Remove upload clears the file and does not open the picker", () => {
  const input = element("input", { type: "file", files: [{ name: "Recording2.mp3", size: 10306499 }] });
  const swap = element("button", { "data-dg-file-swap": "" });
  const card = element("div", { "data-dg-field-id": "small_rec", "data-dg-field-type": "recording_file" });
  card.classList.add("dg-file", "is-ready");
  append(card, input);
  append(card, element("span", { "data-dg-file-name": "" }));
  append(card, element("span", { "data-dg-file-size": "" }));
  append(card, element("span", { "data-dg-file-original": "" }));
  append(card, element("span", { "data-dg-file-sep": "" }));
  append(card, element("input", { "data-dg-file-staged": "", value: "tok" }));
  append(card, swap);

  const listeners = {};
  const document = {
    readyState: "loading",
    addEventListener(type, fn) {
      listeners[type] = listeners[type] || [];
      listeners[type].push(fn);
    },
    querySelector(selector) {
      return this.querySelectorAll(selector)[0] || null;
    },
    querySelectorAll(selector) {
      return card.querySelectorAll(selector).concat(matches(card, selector) ? [card] : []);
    },
  };

  globalThis.window = globalThis;
  globalThis.document = document;
  globalThis.dgPublicForm = {};
  vm.runInThisContext(fs.readFileSync(scriptPath, "utf8"), { filename: scriptPath });
  (listeners.DOMContentLoaded || []).forEach((fn) => fn());

  swap.click();

  assert.equal(input.clickCount, 0, "Remove must not open the file picker");
  assert.equal(input.files, null, "Remove clears the chosen file");
  assert.equal(card.classList.contains("is-empty"), true);
  assert.equal(card.classList.contains("is-ready"), false);
});

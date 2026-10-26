/**
 * All Intersections anonymize path — fake transport only, never the live host.
 */
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const harness = path.join(root, "tests/support/php-anonymize.php");
const artifactDir = path.join(root, "tests/.artifacts/anonymize");
const TEST_KEY = "anon_test_harness_key";
const ORIGINAL_PDF = "%PDF-1.3 original-score\n";
const DOWNLOAD_PDF = "%PDF-1.4 anonymized-score\n";
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const CREATE_PATH = "/v1/anonymizations";
const DEFAULT_CREATE_URL = `https://api.allintersections.com${CREATE_PATH}`;

/**
 * @param {string} id
 */
function downloadPath(id) {
  return `${CREATE_PATH}/${id}/content`;
}

/**
 * @param {Record<string, unknown>} extra
 */
function completedCreate(extra = {}) {
  return {
    status: 200,
    body: JSON.stringify({
      object: "anonymization",
      request_id: "req_01TESTANONYMIZE000000000001",
      idempotency_key: "00000000-0000-4000-8000-000000000001",
      livemode: false,
      data: {
        id: "an_test_completed",
        status: "completed",
        filename: "score.pdf",
        file_type: "application/pdf",
        bytes: DOWNLOAD_PDF.length,
        created: 1700000000,
        ...extra,
      },
    }),
  };
}

/**
 * @param {string} body
 * @param {number} [status]
 */
function rawReply(body, status = 200) {
  return { status, body };
}

/**
 * @param {object} scenario
 */
function runScenario(scenario) {
  fs.mkdirSync(artifactDir, { recursive: true });
  const file = path.join(artifactDir, `${scenario.portalId || "anon"}.scenario.json`);
  fs.writeFileSync(file, JSON.stringify(scenario));
  const r = spawnSync("php", [harness, file], {
    encoding: "utf8",
    env: { ...process.env },
  });
  const out = `${r.stdout || ""}${r.stderr || ""}`;
  let data = null;
  try {
    data = JSON.parse((r.stdout || "").trim());
  } catch {
    data = null;
  }
  return { code: r.status, out, data };
}

function enabledOptions() {
  return {
    anonymize: true,
    anonymizeEndpoint: null,
    anonymizeApiKey: TEST_KEY,
  };
}

test("create envelope plus PDF download replaces the original and records Bearer plus UUID v4", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-valid-pdf",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: enabledOptions(),
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, true);
  assert.equal(data.stored_equals_download, true, "stored bytes must be the download");
  assert.equal(data.stored_equals_original, false);
  assert.equal(data.call_count, 2);
  const [create, download] = data.calls;
  assert.equal(create.method, "POST");
  assert.equal(create.url, DEFAULT_CREATE_URL);
  assert.equal(create.authorization, `Bearer ${TEST_KEY}`);
  assert.match(create.idempotency_key, UUID_V4);
  assert.equal(create.has_multipart, true);
  assert.equal(create.timeout >= 120, true, "create timeout must be >= 120s");
  assert.equal(create.url_has_key, false);
  assert.equal(download.method, "POST");
  assert.equal(download.path, downloadPath("an_test_completed"));
  assert.equal(download.authorization, `Bearer ${TEST_KEY}`);
  assert.match(download.idempotency_key, UUID_V4);
  assert.equal(download.url_has_key, false);
});

test("create 500 twice then 200 reuses the same Idempotency-Key", () => {
  const { code, out, data } = runScenario({
    entry: "anonymizer",
    portalId: "anon-retry-5xx",
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: enabledOptions(),
    script: [
      { status: 500, body: JSON.stringify({ code: "internal_error" }) },
      { status: 500, body: JSON.stringify({ code: "internal_error" }) },
      completedCreate(),
      rawReply(DOWNLOAD_PDF),
    ],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.stored_equals_download, true);
  const creates = data.calls.filter((c) => c.path === CREATE_PATH);
  assert.equal(creates.length, 3);
  const keys = creates.map((c) => c.idempotency_key);
  assert.equal(keys[0], keys[1]);
  assert.equal(keys[1], keys[2]);
  assert.match(keys[0], UUID_V4);
});

test("invalid download bytes keep the original", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-invalid-download",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: enabledOptions(),
    script: [completedCreate(), rawReply("this is not a pdf")],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, true);
  assert.equal(data.stored_equals_original, true);
  assert.equal(data.stored_equals_download, false);
});

test("transport throw keeps the original and submit still succeeds", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-timeout",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: enabledOptions(),
    script: [{ throw: "timed out after 120s" }],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, true);
  assert.equal(data.stored_equals_original, true);
  assert.equal(data.stored_equals_download, false);
});

test("anonymize false makes zero transport calls", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-disabled",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: {
      anonymize: false,
      anonymizeEndpoint: null,
      anonymizeApiKey: TEST_KEY,
    },
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, true);
  assert.equal(data.call_count, 0);
  assert.equal(data.stored_equals_original, true);
});

test("anonymize on + empty key blocks submit and does not store original", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-empty-key",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: {
      anonymize: true,
      anonymizeEndpoint: null,
      anonymizeApiKey: "",
    },
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, false, "empty key must not succeed");
  assert.match(String(data.code || ""), /invalid|anonymize|config/i);
  assert.notEqual(data.stored_equals_original, true);
  assert.equal(data.drive_has_file, false, "identifying original must not be stored");
  assert.equal(data.call_count || 0, 0);
});

test("anonymizeFailClosed on + transport error does not store original", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-fail-closed-timeout",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: {
      ...enabledOptions(),
      anonymizeFailClosed: true,
    },
    script: [{ throw: "timed out after 120s" }],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, false);
  assert.notEqual(data.stored_equals_original, true);
  assert.equal(data.drive_has_file, false);
  assert.equal(data.dests && data.dests.anonymize, "fail");
});

test("anonymizeFailClosed on + invalid download does not store original", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-fail-closed-bad-bytes",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: {
      ...enabledOptions(),
      anonymizeFailClosed: true,
    },
    script: [completedCreate(), rawReply("this is not a pdf")],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, false);
  assert.notEqual(data.stored_equals_original, true);
  assert.equal(data.drive_has_file, false);
  assert.equal(data.dests && data.dests.anonymize, "fail");
});

test("operator log dest is fail when anonymize API errors (fail-open still stores)", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-log-fail-open",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: {
      ...enabledOptions(),
      anonymizeFailClosed: false,
    },
    script: [{ throw: "timed out after 120s" }],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, true);
  assert.equal(data.stored_equals_original, true);
  assert.equal(data.dests && data.dests.anonymize, "fail");
  assert.notEqual(data.dests && data.dests.anonymize, "ok");
});

test("operator log dest is skip when anonymize is off", () => {
  const { code, out, data } = runScenario({
    entry: "pipeline",
    portalId: "anon-log-skip",
    artifactDir,
    filename: "score.pdf",
    original: ORIGINAL_PDF,
    download: DOWNLOAD_PDF,
    options: {
      anonymize: false,
      anonymizeEndpoint: null,
      anonymizeApiKey: TEST_KEY,
    },
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.submit_ok, true);
  assert.equal(data.dests && data.dests.anonymize, "skip");
});

test("file larger than 50 MiB makes zero transport calls and keeps original", () => {
  const oversized = `%PDF-1.3\n${"A".repeat(50 * 1024 * 1024 + 1)}`;
  const { code, out, data } = runScenario({
    entry: "anonymizer",
    portalId: "anon-too-large",
    filename: "score.pdf",
    original: oversized,
    download: DOWNLOAD_PDF,
    options: enabledOptions(),
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.call_count, 0);
  assert.equal(data.stored_equals_original, true);
  assert.equal(data.stored_equals_download, false);
});

/** Applicant name planted in every tag format a recording can carry. */
const APPLICANT = "Sarah Jerrom";

/** Three MPEG-1 Layer III 128 kbps 44.1 kHz frames (417 bytes each). */
function mp3Frames() {
  const frame = Buffer.alloc(417, 0x55);
  frame.set([0xff, 0xfb, 0x90, 0x64], 0);
  return Buffer.concat([frame, frame, frame]);
}

/** @param {number} n */
function syncsafe(n) {
  return Buffer.from([(n >> 21) & 0x7f, (n >> 14) & 0x7f, (n >> 7) & 0x7f, n & 0x7f]);
}

function id3v2Artist() {
  const text = Buffer.concat([Buffer.from([0x00]), Buffer.from(APPLICANT, "latin1")]);
  const size = Buffer.alloc(4);
  size.writeUInt32BE(text.length);
  const frame = Buffer.concat([Buffer.from("TPE1"), size, Buffer.from([0, 0]), text]);
  return Buffer.concat([Buffer.from("ID3"), Buffer.from([3, 0, 0]), syncsafe(frame.length), frame]);
}

function apev2Artist() {
  const value = Buffer.from(APPLICANT, "latin1");
  const head = Buffer.alloc(8);
  head.writeUInt32LE(value.length, 0);
  const item = Buffer.concat([head, Buffer.from("Artist\0", "latin1"), value]);
  /** @param {boolean} isHeader */
  const block = (isHeader) => {
    const b = Buffer.alloc(32);
    b.write("APETAGEX", 0, "latin1");
    b.writeUInt32LE(2000, 8);
    b.writeUInt32LE(item.length + 32, 12);
    b.writeUInt32LE(1, 16);
    b.writeUInt32LE((0x80000000 | (isHeader ? 0x20000000 : 0)) >>> 0, 20);
    return b;
  };
  return Buffer.concat([block(true), item, block(false)]);
}

function id3v1Artist() {
  const tag = Buffer.alloc(128, 0);
  tag.write("TAG", 0, "latin1");
  tag.write(APPLICANT, 33, "latin1");
  return tag;
}

function lyrics3v1Artist() {
  return Buffer.from(`LYRICSBEGIN${APPLICANT} sings the bridgeLYRICSEND`, "latin1");
}

function lyrics3v2Artist() {
  const body = `LYRICSBEGININD00002 0EAR0${String(APPLICANT.length).padStart(3, "0")}${APPLICANT}`;
  return Buffer.from(`${body}${String(body.length).padStart(6, "0")}LYRICS200`, "latin1");
}

/** ID3v2.4 with the footer flag, as written when the tag is appended after the audio. */
function id3v24AppendedArtist() {
  const text = Buffer.concat([Buffer.from([0x03]), Buffer.from(APPLICANT, "utf8")]);
  const frame = Buffer.concat([Buffer.from("TPE1"), syncsafe(text.length), Buffer.from([0, 0]), text]);
  const flags = Buffer.from([4, 0, 0x10]);
  return Buffer.concat([
    Buffer.from("ID3"),
    flags,
    syncsafe(frame.length),
    frame,
    Buffer.from("3DI"),
    flags,
    syncsafe(frame.length),
  ]);
}

test("MP3 recording drops every identifying tag locally and keeps audio frames byte-identical", () => {
  const frames = mp3Frames();
  const tagged = Buffer.concat([id3v2Artist(), frames, apev2Artist(), id3v1Artist()]);
  const { code, out, data } = runScenario({
    entry: "anonymizer",
    portalId: "anon-mp3-tags",
    filename: "Recording.mp3",
    declared_type: "audio/mpeg",
    original_b64: tagged.toString("base64"),
    options: enabledOptions(),
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.call_count, 0, "recordings must not wait on the remote anonymizer");
  const stored = Buffer.from(data.stored_b64, "base64");
  assert.equal(stored.includes(Buffer.from(APPLICANT, "latin1")), false, "applicant name survived");
  assert.deepEqual(stored, frames);
});

test("MP3 recording without tags passes through unchanged with no remote call", () => {
  const frames = mp3Frames();
  const { code, out, data } = runScenario({
    entry: "anonymizer",
    portalId: "anon-mp3-clean",
    filename: "Recording.mp3",
    declared_type: "audio/mpeg",
    original_b64: frames.toString("base64"),
    options: enabledOptions(),
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  assert.equal(data.call_count, 0);
  assert.equal(data.stored_equals_original, true);
});

for (
  const [index, [layout, trailer]] of [
    ["Lyrics3v1 before ID3v1", () => Buffer.concat([lyrics3v1Artist(), id3v1Artist()])],
    ["Lyrics3v1 alone", () => lyrics3v1Artist()],
    ["Lyrics3v2 before ID3v1", () => Buffer.concat([lyrics3v2Artist(), id3v1Artist()])],
    ["APEv2 then Lyrics3v1 then ID3v1", () => Buffer.concat([apev2Artist(), lyrics3v1Artist(), id3v1Artist()])],
    ["an appended ID3v2.4 tag", () => id3v24AppendedArtist()],
    ["an appended ID3v2.4 tag then ID3v1", () => Buffer.concat([id3v24AppendedArtist(), id3v1Artist()])],
  ].entries()
) {
  test(`MP3 recording with ${layout} keeps only the audio frames`, () => {
    const frames = mp3Frames();
    const { code, out, data } = runScenario({
      entry: "anonymizer",
      portalId: `anon-mp3-trailer-${index}`,
      filename: "Recording.mp3",
      declared_type: "audio/mpeg",
      original_b64: Buffer.concat([frames, trailer()]).toString("base64"),
      options: enabledOptions(),
      script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
    });
    assert.equal(code, 0, out);
    assert.ok(data, out);
    const stored = Buffer.from(data.stored_b64, "base64");
    assert.equal(stored.includes(Buffer.from(APPLICANT, "latin1")), false, "applicant name survived");
    assert.deepEqual(stored, frames);
  });
}

test("MP3 recordings joined end to end lose the ID3v2 tag between them", () => {
  const frames = mp3Frames();
  const joined = Buffer.concat([id3v2Artist(), frames, id3v2Artist(), frames]);
  const { code, out, data } = runScenario({
    entry: "anonymizer",
    portalId: "anon-mp3-joined",
    filename: "Recording.mp3",
    declared_type: "audio/mpeg",
    original_b64: joined.toString("base64"),
    options: enabledOptions(),
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  const stored = Buffer.from(data.stored_b64, "base64");
  assert.equal(stored.includes(Buffer.from(APPLICANT, "latin1")), false, "applicant name survived");
  assert.deepEqual(stored, Buffer.concat([frames, frames]));
});

/**
 * Audio bytes that look like an ID3v2 header but fail exactly one check. Each must be left in place.
 * Header layout: "ID3", version, revision, flags, 4 syncsafe size bytes.
 */
const NEAR_MISS_HEADERS = [
  ["text after ID3", Buffer.from("ID3 and 3DI and TAG inside audio", "latin1")],
  ["version 5", Buffer.from([0x49, 0x44, 0x33, 5, 0, 0, 0, 0, 0, 0x10])],
  ["nonzero revision", Buffer.from([0x49, 0x44, 0x33, 3, 1, 0, 0, 0, 0, 0x10])],
  ["low flag bits set", Buffer.from([0x49, 0x44, 0x33, 3, 0, 0x01, 0, 0, 0, 0x10])],
  ["size byte with high bit", Buffer.from([0x49, 0x44, 0x33, 3, 0, 0, 0x80, 0, 0, 0x10])],
  ["tag longer than the file", Buffer.from([0x49, 0x44, 0x33, 3, 0, 0, 0, 0x7f, 0x7f, 0x7f])],
];

for (const [index, [why, header]] of NEAR_MISS_HEADERS.entries()) {
  test(`audio containing an ID3-like header (${why}) is not cut`, () => {
    const frames = mp3Frames();
    header.copy(frames, 600);
    const { code, out, data } = runScenario({
      entry: "anonymizer",
      portalId: `anon-mp3-lookalike-${index}`,
      filename: "Recording.mp3",
      declared_type: "audio/mpeg",
      original_b64: frames.toString("base64"),
      options: enabledOptions(),
      script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
    });
    assert.equal(code, 0, out);
    assert.ok(data, out);
    assert.equal(data.call_count, 0);
    assert.equal(data.stored_equals_original, true);
  });
}

test("a leading ID3v2 tag with a nonzero revision byte is still removed", () => {
  const frames = mp3Frames();
  const tag = id3v2Artist();
  tag[4] = 1;
  const { code, out, data } = runScenario({
    entry: "anonymizer",
    portalId: "anon-mp3-leading-rev",
    filename: "Recording.mp3",
    declared_type: "audio/mpeg",
    original_b64: Buffer.concat([tag, frames]).toString("base64"),
    options: enabledOptions(),
    script: [completedCreate(), rawReply(DOWNLOAD_PDF)],
  });
  assert.equal(code, 0, out);
  assert.ok(data, out);
  const stored = Buffer.from(data.stored_b64, "base64");
  assert.equal(stored.includes(Buffer.from(APPLICANT, "latin1")), false, "applicant name survived");
  assert.deepEqual(stored, frames);
});

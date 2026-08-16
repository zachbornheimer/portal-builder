/**
 * Film every 2027 CFS configuration on production.
 *
 *   npx playwright test --project=filmed e2e/filmed/cfs-2027-prod-walk.spec.ts
 *
 * Videos: test-results/ then copied to tests/.artifacts/videos/cfs-2027-prod/
 * Submits real rows to the live sheet / Drive. Receipts go to zbornheimer@isjac.org.
 */
import { test, expect } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'

const PORTAL_PATH = '/portal/2027-call-for-scores-and-papers'
const FILES = path.resolve('tests/.artifacts/prod-walk-files')
const DAY = new Date().toISOString().slice(0, 10)

test.use({
  baseURL: 'https://isjac.org',
  video: 'on',
  screenshot: 'on',
  trace: 'on',
})

test.describe.configure({ mode: 'serial' })
test.setTimeout(420_000)

const APPLICANT = {
  title: 'Mr',
  name: 'Zach Bornheimer',
  email: 'zbornheimer@isjac.org',
  affiliation: 'ISJAC',
  address: '100 Composers Way',
  city: 'Raleigh',
  zip: '27601',
  phone: '+1-919-555-2015',
}

type TScoreWalk = {
  id: string
  radio: RegExp
  titleSelector: string
  scoreField: string
  recField: string
  scoreFile: string
  recFile: string
  bioFile: string
  workTitle: string
}

const SCORE_WALKS: TScoreWalk[] = [
  {
    id: 'large',
    radio: /Large Ensemble/i,
    titleSelector: '#sub_large_title',
    scoreField: 'large_score',
    recField: 'large_rec',
    scoreFile: 'large-score.pdf',
    recFile: 'large-rec.mp3',
    bioFile: 'bio-large.pdf',
    workTitle: `VIDEO WALK — Large Ensemble — ${DAY}`,
  },
  {
    id: 'small',
    radio: /Small Ensemble/i,
    titleSelector: '#sub_small_title',
    scoreField: 'small_score',
    recField: 'small_rec',
    scoreFile: 'small-score.pdf',
    recFile: 'small-rec.mp3',
    bioFile: 'bio-small.pdf',
    workTitle: `VIDEO WALK — Small Ensemble — ${DAY}`,
  },
  {
    id: 'arrangement',
    radio: /Arrangement/i,
    titleSelector: '#sub_arrangement_title',
    scoreField: 'arrangement_score',
    recField: 'arrangement_rec',
    scoreFile: 'arrangement-score.pdf',
    recFile: 'arrangement-rec.mp3',
    bioFile: 'bio-arrangement.pdf',
    workTitle: `VIDEO WALK — Arrangement — ${DAY}`,
  },
  {
    id: 'first_takes',
    radio: /First Takes/i,
    titleSelector: '#sub_first_takes_title',
    scoreField: 'first_takes_score',
    recField: 'first_takes_rec',
    scoreFile: 'first-takes-score.pdf',
    recFile: 'first-takes-rec.mp3',
    bioFile: 'bio-first-takes.pdf',
    workTitle: `VIDEO WALK — First Takes — ${DAY}`,
  },
  {
    id: 'student',
    radio: /Student\/Young Artist|Student/i,
    titleSelector: '#sub_student_title',
    scoreField: 'student_score',
    recField: 'student_rec',
    scoreFile: 'student-score.pdf',
    recFile: 'student-rec.mp3',
    bioFile: 'bio-student.pdf',
    workTitle: `VIDEO WALK — Student Young Artist — ${DAY}`,
  },
]

function filePath(name: string) {
  const full = path.join(FILES, name)
  if (!fs.existsSync(full)) {
    throw new Error(`missing identifying file: ${full}`)
  }
  return full
}

async function selectOrInject(
  select: import('@playwright/test').Locator,
  value: string,
  label: string,
) {
  const byValue = await select.locator(`option[value="${value}"]`).count()
  if (byValue > 0) {
    await select.selectOption(value)
    return
  }
  const byLabel = await select.locator('option', { hasText: label }).count()
  if (byLabel > 0) {
    await select.selectOption({ label })
    return
  }
  await select.evaluate(
    (el, pair) => {
      const opt = document.createElement('option')
      opt.value = pair.value
      opt.textContent = pair.label
      el.appendChild(opt)
    },
    { value, label },
  )
  await select.selectOption(value)
}

async function signInPaidMember(page: import('@playwright/test').Page) {
  const user = process.env.ISJAC_MEMBER_USER || ''
  const pass = process.env.ISJAC_MEMBER_PASS || ''
  if (!user || !pass) {
    throw new Error('set ISJAC_MEMBER_USER and ISJAC_MEMBER_PASS for the live members portal')
  }
  await page.goto(
    `https://isjac.org/wp-login.php?redirect_to=${encodeURIComponent('https://isjac.org' + PORTAL_PATH)}`,
    { waitUntil: 'domcontentloaded', timeout: 90_000 },
  )
  const login = page.locator('#user_login')
  const pwd = page.locator('#user_pass')
  await login.waitFor({ state: 'visible', timeout: 30_000 })
  await login.click()
  await login.fill(user)
  if ((await login.inputValue()) !== user) {
    throw new Error('login field did not keep the username')
  }
  await pwd.click()
  await pwd.fill(pass)
  if ((await pwd.inputValue()).length !== pass.length) {
    throw new Error('password field length mismatch')
  }
  if ((await login.inputValue()) !== user) {
    throw new Error('username was overwritten after filling the password')
  }
  await page.locator('#wp-submit').click()
  await page.waitForLoadState('domcontentloaded')
}

async function openPublicForm(page: import('@playwright/test').Page) {
  await signInPaidMember(page)
  const res = await page.goto(PORTAL_PATH, {
    waitUntil: 'domcontentloaded',
    timeout: 90_000,
  })
  expect(res === null || (res !== null && res.status() >= 200 && res.status() < 400)).toBeTruthy()
  const html = await page.content()
  expect(html, 'live plugin must be 0.1.0').toContain('ver=0.1.0')
  const closed = page.locator('[data-dg-portal-state="closed"]')
  if (await closed.count()) {
    throw new Error(`portal is closed: ${await closed.innerText()}`)
  }
  const restricted = page.locator('[data-dg-portal-state="restricted"]')
  if (await restricted.count()) {
    throw new Error(`portal restricted after login: ${await restricted.innerText()}`)
  }
  await expect(page.locator('form.dg-portal-submit-form')).toBeVisible({
    timeout: 45_000,
  })
  await expect(page.getByRole('heading', { name: /2027 Call for Scores/i })).toBeVisible()
}

async function fillApplicant(page: import('@playwright/test').Page) {
  const form = page.locator('form.dg-portal-submit-form')
  await form.locator('#sub_title').fill(APPLICANT.title)
  await form.locator('#sub_name').fill(APPLICANT.name)
  await form.locator('#sub_email').fill(APPLICANT.email)
  await form.locator('#sub_inst_affil').fill(APPLICANT.affiliation)
  await form.locator('#sub_address_first_part').fill(APPLICANT.address)
  await form.locator('#sub_city').fill(APPLICANT.city)
  await selectOrInject(form.locator('select[name="sub_country"]'), 'US', 'United States')
  const state = form.locator('select[name="sub_state"]')
  await expect
    .poll(async () => state.locator('option').count(), { timeout: 15_000 })
    .toBeGreaterThan(1)
  await selectOrInject(state, 'NC', 'North Carolina')
  await form.locator('#sub_zip').fill(APPLICANT.zip)
  await form.locator('#sub_phone').fill(APPLICANT.phone)
}

async function confirmFileCard(
  page: import('@playwright/test').Page,
  fieldId: string,
  fileLabel: RegExp,
  diskPath: string,
) {
  const card = page.locator(`[data-dg-field-id="${fieldId}"]`)
  await expect(card).toBeVisible()
  await card.scrollIntoViewIfNeeded()
  await card.getByLabel(fileLabel).setInputFiles(diskPath)
  await expect(card).toHaveClass(/is-ready/)
  await expect(card).toHaveClass(/is-staged/, { timeout: 180_000 })
  const original = path.basename(diskPath)
  await expect(card.locator('[data-dg-file-original]')).toContainText(original)
  await card.getByRole('button', { name: /Open to confirm/i }).click()
  // Confirm is in-page; a tab cannot callback.
  const dialog = page.getByRole('dialog', { name: /Confirm this file/i })
  await expect(dialog.or(card)).toBeVisible()
  await expect(card).toHaveClass(/is-opened/, { timeout: 15_000 })
  await expect(card.getByText(/This file opened and is readable/i)).toBeVisible()
  await expect(card.getByRole('button', { name: /^Open$/ })).toBeVisible()
  if (await dialog.isVisible().catch(() => false)) {
    await page.keyboard.press('Escape')
  }
}

async function acceptAnonymizeAck(page: import('@playwright/test').Page) {
  const ack = page.getByLabel(/I certify that my scores and recordings/i)
  if (await ack.count()) {
    await ack.check()
  }
}

async function submitAndExpectSuccess(page: import('@playwright/test').Page, label: string) {
  await acceptAnonymizeAck(page)
  await page.getByRole('button', { name: 'Submit application' }).click({
    timeout: 240_000,
    noWaitAfter: true,
  })
  const success = page.locator('[data-dg-submit-status="success"]')
  const error = page.locator('[data-dg-submit-status="error"]')
  await expect(success.or(error)).toBeVisible({ timeout: 240_000 })
  if (await error.count()) {
    throw new Error(`${label} submit failed: ${await error.innerText()}`)
  }
  await expect(success).toBeVisible()
}

test('poster sessions — identifying PDFs, anonymize, submit', async ({ page }) => {
  await openPublicForm(page)
  await fillApplicant(page)
  await confirmFileCard(page, 'bio', /Bio/, filePath('bio-poster.pdf'))
  await page.getByLabel(/Poster Sessions/i).check()
  await expect(page.locator('[data-dg-field-id="poster_description"]')).toBeVisible()
  await confirmFileCard(
    page,
    'poster_description',
    /Brief Description/,
    filePath('poster-brief.pdf'),
  )
  await submitAndExpectSuccess(page, 'poster')
})

test('research papers — identifying PDFs, anonymize, submit', async ({ page }) => {
  await openPublicForm(page)
  await fillApplicant(page)
  await confirmFileCard(page, 'bio', /Bio/, filePath('bio-papers.pdf'))
  await page.getByLabel(/Research\/Analysis Papers/i).check()
  const titleInput = page.locator('#sub_paper_title')
  await expect(titleInput).toBeVisible()
  await titleInput.fill(`VIDEO WALK — Research Paper — ${DAY}`)
  await confirmFileCard(page, 'paper_abstract', /Abstract/, filePath('paper-abstract.pdf'))
  await submitAndExpectSuccess(page, 'papers')
})

for (const walk of SCORE_WALKS) {
  test(`scores ${walk.id} — identifying PDF+MP3, anonymize, submit`, async ({ page }) => {
    await openPublicForm(page)
    await fillApplicant(page)
    await confirmFileCard(page, 'bio', /Bio/, filePath(walk.bioFile))
    await page.getByLabel(/Scores\/Recordings/i).check()
    await expect(page.getByLabel(walk.radio)).toBeVisible()
    await page.getByLabel(walk.radio).check()
    const titleInput = page.locator(walk.titleSelector)
    await expect(titleInput).toBeEnabled()
    await titleInput.fill(walk.workTitle)
    await confirmFileCard(page, walk.scoreField, /^Score/, filePath(walk.scoreFile))
    await confirmFileCard(page, walk.recField, /Recording/, filePath(walk.recFile))
    await submitAndExpectSuccess(page, walk.id)
  })
}

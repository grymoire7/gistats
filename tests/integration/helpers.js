// Shared helpers for the Playwright integration specs.

const pad = (n) => String(n).padStart(2, '0');
const format = (d) =>
  `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;

// Far-future timestamps, one minute apart and increasing, so entries never hit
// the unique (user_id, occurred_at) index and the newest sorts first (DESC list).
// The year advances with Playwright's worker index: a worker restarted after a failure
// restarts the counter, and this keeps its values clear of the earlier worker's.
let counter = 0;
const BASE_MS = Date.UTC(2090 + Number(process.env.TEST_WORKER_INDEX || 0), 0, 1);

function uniqueOccurredAt() {
  return format(new Date(BASE_MS + counter++ * 60000));
}

// Current local time as a datetime-local value (for specs that need today's month).
function nowLocal() {
  const d = new Date();
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// The hidden stool-type input and datetime field are set directly, as the old scripts did.
async function fillEntry(page, { occurredAt = uniqueOccurredAt(), stoolType = '4' } = {}) {
  await page.evaluate(
    ({ occurredAt, stoolType }) => {
      document.querySelector('[name="occurred_at"]').value = occurredAt;
      document.getElementById('stool-type-input').value = stoolType;
    },
    { occurredAt, stoolType }
  );
}

module.exports = { uniqueOccurredAt, nowLocal, fillEntry };

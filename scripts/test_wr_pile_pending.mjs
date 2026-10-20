import assert from 'node:assert/strict';

function waitingRoomCardsForDisplay(cards, pending) {
  const wr = cards || [];
  if (!pending?.size) return wr;
  return wr.filter(c => c?.instance_id && !pending.has(c.instance_id));
}

function wrCardInWaitingRoom(state, iid) {
  if (!state?.players || !iid) return null;
  for (const pid of ['p1', 'p2']) {
    const card = (state.players[pid]?.waiting_room || []).find(c => c?.instance_id === iid);
    if (card) return { pid, card };
  }
  return null;
}

/** Mirror board-render reconcile: drop pending ids already in WR with no flight. */
function reconcileStaleWrPending(state, pending, flightActive) {
  if (!pending?.size || !state?.players) return { pending, released: [] };
  const released = [];
  for (const iid of [...pending]) {
    if (wrCardInWaitingRoom(state, iid) && !flightActive(iid)) {
      pending.delete(iid);
      released.push(iid);
    }
  }
  return { pending, released };
}

const cards = [
  { instance_id: 'a', name_en: 'Low', cost: 2 },
  { instance_id: 'b', name_en: 'Rurino 15', cost: 15 },
  { instance_id: 'c', name_en: 'Hime 15', cost: 15 },
];

const pending = new Set(['b', 'c']);
assert.equal(waitingRoomCardsForDisplay(cards, pending).length, 1, 'pending hides WR faces');
assert.equal(waitingRoomCardsForDisplay(cards, null).length, 3, 'cleared pending shows all');
assert.equal(waitingRoomCardsForDisplay(cards, new Set()).length, 3, 'empty set shows all');

const shown = waitingRoomCardsForDisplay(cards, null);
assert.equal(shown[shown.length - 1].cost, 15);
assert.equal(shown[shown.length - 1].name_en, 'Hime 15');

// Stale pending: card already in WR, no flight — should not hide top face after reconcile.
const board = {
  players: {
    p1: { waiting_room: cards },
    p2: { waiting_room: [] },
  },
};
const stalePending = new Set(['c']);
const { pending: afterReconcile, released } = reconcileStaleWrPending(
  board,
  stalePending,
  () => false,
);
assert.deepEqual(released, ['c'], 'stale top-card pending released');
assert.equal(afterReconcile.size, 0, 'pending emptied when all stale');
assert.equal(
  waitingRoomCardsForDisplay(cards, afterReconcile.size ? afterReconcile : null).length,
  3,
  'reconciled pile shows full WR',
);

// In-flight pending kept until flight ends.
const inflightPending = new Set(['b', 'c']);
const kept = reconcileStaleWrPending(board, inflightPending, iid => iid === 'c');
assert.deepEqual(kept.released, ['b'], 'only non-flight stale id released');
assert.equal(kept.pending.has('c'), true, 'in-flight id stays pending');
assert.equal(waitingRoomCardsForDisplay(cards, kept.pending).length, 2, 'stale id revealed; in-flight id still hidden');

// Partial release: top 15-cost revealed, under 15-cost still pending.
const partialPending = new Set(['b', 'c']);
const partial = reconcileStaleWrPending(board, partialPending, iid => iid === 'b');
assert.deepEqual(partial.released, ['c'], 'top stale id released first');
assert.equal(partial.pending.has('b'), true, 'under-card flight still latched');

console.log('wr-pile-pending-repaint: ok');

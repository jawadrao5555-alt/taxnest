'use strict';

const MAX_HANDOFFS = 2;

function handoffCount(state, target) {
  return state && state.target === target && Number.isInteger(state.count)
    ? Math.max(0, state.count) : 0;
}

function shouldStopHandoff(state, target) {
  return handoffCount(state, target) >= MAX_HANDOFFS;
}

function nextHandoff(state, target) {
  return { target, count: handoffCount(state, target) + 1 };
}

module.exports = { MAX_HANDOFFS, shouldStopHandoff, nextHandoff };

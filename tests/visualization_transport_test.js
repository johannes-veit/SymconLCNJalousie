#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'LCNJalousie', 'module.html'), 'utf8');
const modulePhp = fs.readFileSync(path.join(root, 'LCNJalousie', 'module.php'), 'utf8');
if (!html.includes('/*__LCNJAL_INITIAL_STATE__*/')) {
    throw new Error('Initial-state placeholder missing from module.html');
}
if (!modulePhp.includes("'jalInitialState = ' . $initialState . ';'")) {
    throw new Error('Server-side initial-state injection missing from module.php');
}
const renderedHtml = html.replace(
    '/*__LCNJAL_INITIAL_STATE__*/',
    'jalInitialState = {position:63, rotation:40, active:true, controlsEnabled:true, intermediateAllowed:true, shakeFreeToggleEnabled:true, stopEnabled:true, commandActive:false, referenced:true};'
);
const scripts = [...renderedHtml.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)]
    .map((match) => match[1])
    .filter((script) => script.trim() !== '');
if (scripts.length !== 1) {
    throw new Error(`Expected one inline script, found ${scripts.length}`);
}

class FakeClassList {
    constructor() {
        this.values = new Set();
    }
    add(...names) {
        names.forEach((name) => this.values.add(name));
    }
    remove(...names) {
        names.forEach((name) => this.values.delete(name));
    }
    toggle(name, force) {
        if (force === true) {
            this.values.add(name);
            return true;
        }
        if (force === false) {
            this.values.delete(name);
            return false;
        }
        if (this.values.has(name)) {
            this.values.delete(name);
            return false;
        }
        this.values.add(name);
        return true;
    }
}

class FakeElement {
    constructor(id = '') {
        this.id = id;
        this.value = '0';
        this.checked = false;
        this.disabled = false;
        this.hidden = false;
        this.textContent = '';
        this.dataset = {};
        this.style = {};
        this.classList = new FakeClassList();
        this.listeners = new Map();
    }
    addEventListener(name, handler) {
        this.listeners.set(name, handler);
    }
}

const ids = [
    'jal-tile', 'jal-warning-text', 'jal-reset-error', 'jal-position',
    'jal-position-value', 'jal-rotation', 'jal-rotation-value', 'jal-stop',
    'jal-shakefree', 'jal-shakefree-text', 'jal-run-status',
    'jal-run-status-text', 'jal-blind-curtain'
];
const elements = new Map(ids.map((id) => [id, new FakeElement(id)]));
const positionButton = new FakeElement('position-button');
positionButton.dataset.action = 'Position';
positionButton.dataset.value = '0';
positionButton.classList.add('jal-round-button', 'jal-position-command');
const slatButton = new FakeElement('slat-button');
slatButton.dataset.action = 'Drehgrad';
slatButton.dataset.value = '50';
slatButton.classList.add('jal-round-button', 'jal-slat-command');
const allButtons = [positionButton, slatButton];

const document = {
    getElementById(id) {
        if (!elements.has(id)) {
            elements.set(id, new FakeElement(id));
        }
        return elements.get(id);
    },
    querySelectorAll(selector) {
        if (selector === '[data-action][data-value]') {
            return allButtons;
        }
        if (selector.includes('jal-position-command')) {
            return [positionButton];
        }
        if (selector.includes('jal-slat-command')) {
            return [slatButton];
        }
        if (selector.includes('jal-round-button.is-current')) {
            return allButtons.filter((button) => button.classList.values.has('is-current'));
        }
        if (selector.includes('jal-slat-line')) {
            return [];
        }
        return [];
    }
};

const windowListeners = new Map();
let nextTimer = 1;
const timers = new Map();
const windowObject = {
    addEventListener(name, handler) {
        windowListeners.set(name, handler);
    },
    setTimeout(handler, delay) {
        const id = nextTimer++;
        timers.set(id, {handler, delay});
        return id;
    },
    clearTimeout(id) {
        timers.delete(id);
    }
};

let calls = [];
const context = vm.createContext({
    console,
    document,
    window: windowObject,
    navigator: {onLine: true},
    requestAction(ident, value) {
        calls.push([ident, value]);
    },
    Date,
    Math,
    Number,
    String,
    Boolean,
    JSON,
    Promise,
    setTimeout: windowObject.setTimeout,
    clearTimeout: windowObject.clearTimeout
});

vm.runInContext(scripts[0], context, {filename: 'module.html'});
if (elements.get('jal-position-value').textContent !== '63 %') {
    throw new Error(`Initial server state was not rendered immediately: ${elements.get('jal-position-value').textContent}`);
}
if (elements.get('jal-position').disabled) {
    throw new Error('Initial server state rendered controls as disabled');
}
context.handleMessage({
    active: true,
    controlsEnabled: true,
    intermediateAllowed: true,
    shakeFreeToggleEnabled: true,
    stopEnabled: true,
    commandActive: false,
    referenced: true
});

// A first command is sent exactly once; a rapid different command is queued,
// not discarded and not sent in parallel.
calls = [];
context.sendJalousieAction('Position', 0);
context.sendJalousieAction('Position', 100);
if (calls.length !== 1 || calls[0][0] !== 'Position' || calls[0][1] !== 0) {
    throw new Error(`Initial/queued command handling failed: ${JSON.stringify(calls)}`);
}
if (elements.get('jal-position').disabled) {
    throw new Error('Position control was visually disabled during transport');
}

// A newer rapid target replaces the older queued target (latest target wins).
context.sendJalousieAction('Position', 75);
if (calls.length !== 1) {
    throw new Error('Queued target was sent in parallel');
}

// The next server state releases the current request and schedules the queued
// target. Execute the fake queue timer and verify only the latest value is sent.
context.handleMessage({
    active: true,
    controlsEnabled: true,
    intermediateAllowed: true,
    commandActive: true,
    orderType: 1,
    targetPosition: 0,
    position: 42,
    referenced: true,
    stopEnabled: true
});
const queueTimerEntry = [...timers.entries()].find(([, item]) => item.delay === 75);
if (!queueTimerEntry) {
    throw new Error('Queued follow-up timer missing');
}
timers.delete(queueTimerEntry[0]);
queueTimerEntry[1].handler();
if (calls.length !== 2 || calls[1][0] !== 'Position' || calls[1][1] !== 75) {
    throw new Error(`Latest-wins queue failed: ${JSON.stringify(calls)}`);
}

// STOP remains possible immediately and cancels a locally queued follow-up.
context.sendJalousieAction('Position', 25); // becomes queued while 75 is in flight
context.sendJalousieAction('Stop', true);
if (calls.length !== 3 || calls[2][0] !== 'Stop') {
    throw new Error(`STOP availability failed: ${JSON.stringify(calls)}`);
}

// A synchronous transport exception is handled locally and never retried.
context.handleMessage({commandActive: true, controlsEnabled: true, stopEnabled: true});
calls = [];
context.requestAction = () => {
    calls.push(['throw']);
    throw new Error('ClientException: Failed to fetch, uri=/api/');
};
context.sendJalousieAction('Stop', true);
if (calls.length !== 1) {
    throw new Error('Synchronous failure was retried');
}
if (!elements.get('jal-warning-text').textContent.includes('nicht automatisch wiederholt')) {
    throw new Error('Synchronous transport warning missing');
}

// A thenable/rejected promise is caught and does not become an unhandled retry.
context.handleMessage({commandActive: true, controlsEnabled: true, stopEnabled: true});
calls = [];
context.requestAction = () => {
    calls.push(['promise']);
    return Promise.reject(new Error('Failed to fetch, uri=/api/'));
};
context.sendJalousieAction('Stop', true);
Promise.resolve().then(() => Promise.resolve()).then(() => {
    if (calls.length !== 1) {
        throw new Error('Asynchronous failure was retried');
    }
    if (!elements.get('jal-warning-text').textContent.includes('nicht automatisch wiederholt')) {
        throw new Error('Asynchronous transport warning missing');
    }

    // The global rejection handler suppresses only the known API transport error.
    let prevented = false;
    const rejectionHandler = windowListeners.get('unhandledrejection');
    if (typeof rejectionHandler !== 'function') {
        throw new Error('unhandledrejection handler missing');
    }
    rejectionHandler({
        reason: new Error('ClientException: Failed to fetch, uri=/api/'),
        preventDefault() { prevented = true; }
    });
    if (!prevented) {
        throw new Error('Known API rejection was not suppressed');
    }

    console.log('VISUALIZATION TRANSPORT TEST OK');
}).catch((error) => {
    console.error(error.stack || error.message || String(error));
    process.exitCode = 1;
});

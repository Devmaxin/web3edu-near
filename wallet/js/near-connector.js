/**
 * Web3Edu NEAR — Wallet Connector (Vanilla JS)
 *
 * Uses @hot-labs/near-connect (HOT Connect) for NEAR wallet integration.
 * Supports NEP-413 message signing for authentication.
 *
 * This file is bundled with esbuild and output to frontend/js/wallet.bundle.js
 */

import { NearConnector } from '@hot-labs/near-connect';

// Application configuration
const CONFIG = {
    network: 'testnet',
    recipient: 'web3edu-near',
    apiBase: '', // Same-origin API endpoints
};

// Global connector instance (lazy-initialized)
let connector = null;

/**
 * Initialize the NEAR connector.
 * Call this once when the wallet UI is first needed.
 */
async function initConnector() {
    if (connector) return connector;

    connector = new NearConnector({
        network: CONFIG.network,
        features: {
            signMessage: true,
            signTransaction: false,
            signInWithoutAddKey: true,
            signAndSendTransaction: false,
            signAndSendTransactions: false,
        },
    });

    return connector;
}

/**
 * Get the connected wallet instance.
 */
async function getWallet() {
    await initConnector();
    return await connector.wallet();
}

/**
 * Connect to a wallet and return account info.
 * Returns { accountId, publicKey } or null.
 */
async function connectWallet() {
    await initConnector();
    const wallet = await connector.connect();
    const accounts = await wallet.getAccounts();
    if (!accounts || accounts.length === 0) {
        return null;
    }
    return {
        accountId: accounts[0].accountId,
        publicKey: accounts[0].publicKey,
    };
}

/**
 * Disconnect from the wallet.
 */
async function disconnectWallet() {
    if (connector) {
        const wallet = await connector.wallet();
        await connector.disconnect(wallet);
    }
}

/**
 * Sign a NEP-413 message using the connected wallet.
 * Returns { signature, publicKey } or null.
 */
async function signMessage(message, nonce, callbackUrl = null) {
    const wallet = await getWallet();

    const params = {
        message,
        recipient: CONFIG.recipient,
        nonce,
    };

    if (callbackUrl) {
        params.callbackUrl = callbackUrl;
    }

    const signature = await wallet.signMessage(params);
    return {
        signature: signature.signature,
        publicKey: signature.publicKey,
    };
}

/**
 * Request a challenge from the server.
 * Returns { challenge_id, nonce, recipient, expires_at } or null.
 */
async function requestChallenge(action, userId = null) {
    const body = { action };
    if (userId) {
        body.user_id = userId;
    }

    const response = await fetch(`${CONFIG.apiBase}/auth/near-challenge.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(error.error || 'Failed to request challenge');
    }

    const data = await response.json();
    if (!data.success) {
        throw new Error(data.error || 'Failed to request challenge');
    }

    return data;
}

/**
 * Submit a signed NEP-413 signature to the server for verification.
 * Returns { success, user_id, action, is_new } or throws.
 */
async function submitSignature(challengeId, accountId, publicKey, signature, signedPayload) {
    const response = await fetch(`${CONFIG.apiBase}/auth/near-verify.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify({
            challenge_id: challengeId,
            account_id: accountId,
            public_key: publicKey,
            signature,
            signed_payload: signedPayload,
        }),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(error.error || 'Signature verification failed');
    }

    const data = await response.json();
    if (!data.success) {
        throw new Error(data.error || 'Signature verification failed');
    }

    return data;
}

/**
 * Get CSRF token from the page (set by PHP session).
 */
function getCsrfToken() {
    // Try to read from a meta tag or input set by the page
    const meta = document.querySelector('meta[name="csrf_token"]');
    if (meta) return meta.getAttribute('content');

    const input = document.querySelector('input[name="csrf_token"]');
    if (input) return input.value;

    return '';
}

/**
 * Expose the API globally for use in inline scripts.
 */
window.Web3EduNear = {
    initConnector,
    connectWallet,
    disconnectWallet,
    getWallet,
    signMessage,
    requestChallenge,
    submitSignature,
    getCsrfToken,
};

export { NearConnector, initConnector, connectWallet, disconnectWallet, getWallet, signMessage, requestChallenge, submitSignature };

# HTTPS & Local SSL Logic

This document explains the hybrid HTTP/HTTPS architecture used in the Document Tracking System (DTS) Prototype and provides a technical summary of how to manage local SSL certificates.

## Table of Contents
1. [HTTP vs. HTTPS: The Core Difference](#http-vs-https-the-core-difference)
2. [The Hybrid Architecture (Why both?)](#the-hybrid-architecture-why-both)
3. [The "Mixed Content" Challenge](#the-mixed-content-challenge)
4. [The "Vite Priming" Workaround](#the-vite-priming-workaround)
5. [Troubleshooting & Resolution Summary](#troubleshooting--resolution-summary)

---

## HTTP vs. HTTPS: The Core Difference

-   **HTTP (Hypertext Transfer Protocol):** Transmits data in plain text. It is faster to set up for local development but is considered "insecure" by modern browsers.
-   **HTTPS (HTTP Secure):** Encrypts data using SSL/TLS certificates. Modern browsers **require** HTTPS to grant access to sensitive hardware, such as the **camera** (needed for the DTS QR Scanner).

---

## The Hybrid Architecture (Why both?)

In this project's development environment, we use a split approach:

| Component | Protocol | Port | Reason |
|:---|:---|:---|:---|
| **Laravel Backend** | `http://` | `3000` | Simplified local routing and database communication. |
| **Vite Frontend** | `https://` | `5173` | Required to serve secure JS/CSS assets and enable the QR Scanner. |

**The Conflict:** Because the main application URL (`APP_URL`) is `http://localhost:3000`, the browser considers the entire site "Insecure," even if it loads some assets over HTTPS.

---

## The "Mixed Content" Challenge

When a page served over `http` tries to load resources over `https` (or vice versa), it is called **Mixed Content**.
-   **Blocked Features:** Even if the Vite server is secure, the browser will block `navigator.mediaDevices.getUserMedia` (the camera API) if the top-level URL is not `https`.
-   **Self-Signed Certificates:** Because we use `mkcert` to generate local certificates, your browser doesn't recognize them by default, leading to "Your connection is not private" warnings.

---

## The "Vite Priming" Workaround

To make the QR scanner and secure assets work without a complex proxy setup, we use a technique called **"Vite Priming"**:

1.  **Direct Access:** The developer manually visits `https://localhost:5173`.
2.  **Manual Trust:** The developer clicks "Advanced" &rarr; "Proceed to localhost (unsafe)".
3.  **Exception Storage:** The browser stores a temporary security exception for that port.
4.  **Integration:** When you then visit `http://localhost:3000`, the browser is now allowed to download the (now-trusted) secure assets from the Vite server.

---

## Troubleshooting & Resolution Summary

### 1. "Camera Access Denied"
-   **Cause:** You are accessing the site via `http://` and haven't "primed" the Vite server.
-   **Fix:** Visit `https://localhost:5173` first, accept the risk, then return to the app.

### 2. "Vite Manifest Not Found"
-   **Cause:** `npm run dev` is not running, or the Vite server crashed.
-   **Fix:** Ensure the Vite pillar is running in your `composer run dev` terminal.

### 3. "Broken CSS/JS on Network Devices"
-   **Cause:** `localhost` is only reachable from your machine. A phone on the same Wi-Fi cannot see your computer's `localhost`.
-   **Limitation:** Full network-wide HTTPS is complex for local development. For now, hardware features (QR Scanning) are only supported when developing on the **local machine**.

---

## Summary of `vite.config.js` logic
The project uses `mkcert` generated files:
```javascript
https: {
    key: fs.readFileSync('localhost.key'),
    cert: fs.readFileSync('localhost.crt'),
}
```
If these files are missing, the Vite server will fail to start. Ensure you have run `mkcert localhost` in the project root.

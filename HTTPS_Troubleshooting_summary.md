# DTS Prototype Troubleshooting Summary

This document summarizes the series of issues encountered and steps taken to configure the local development environment for the DTS Prototype, enabling access from both the local machine and other devices on the network.

---

### 1. Initial Problem: Broken UI on Network Devices

- **Symptom:** The application worked on `http://localhost:3001` but the design (CSS/JS) was broken when accessed from a phone on the same network (`http://<ip-address>:3001`). The QR scanner also failed due to not having a secure (HTTPS) context.
- **Root Cause:**
    1.  The Vite development server was only listening on `localhost`, so network devices couldn't access it.
    2.  Asset URLs in the HTML were hardcoded to `localhost`, which is unreachable from other devices.
    3.  Camera access for the QR scanner requires a secure `https://` context, but the app was served over `http://`.

---

### 2. Solution Part 1: Enabling Network Access & HTTPS

- **Step 2.1: Expose Vite to the Network:**
    - **Action:** Modified the `dev` script in `package.json` to `vite --host`.
    - **Result:** Made the Vite server accessible over the local network.

- **Step 2.2: Enable HTTPS for Vite:**
    - **Action:** Installed `mkcert` to create a trusted local SSL certificate (`localhost.crt` & `localhost.key`).
    - **Action:** Configured `vite.config.js` to use these certificate files, enabling `https://` for the Vite server. This was a necessary prerequisite for allowing camera access.

---

### 3. Intermediate Problem: The `[::]` CORS Error

- **Symptom:** After enabling HTTPS, the application broke on `localhost` as well. The browser console showed a CORS error trying to connect to a server at `https://[::]:5173`.
- **Root Cause:** A conflict between the `--host` flag and Vite's server configuration caused Vite to announce its address using the invalid IPv6 "all interfaces" address `[::]`, which browsers cannot connect to.
- **Solution:**
    - **Action:** Added `hmr: { host: 'localhost' }` to the `server` block in `vite.config.js`.
    - **Result:** This explicitly told the Vite client running in the browser to connect to `localhost` for live updates, fixing the invalid address.

---

### 4. Intermediate Problem: Self-Signed Certificate Trust

- **Symptom:** The `[::]` error was gone, but a new CORS error appeared: `CORS request did not succeed`.
- **Root Cause:** The browser on the laptop did not trust the self-signed certificate for `https://localhost:5173` that `mkcert` had generated.
- **Solution:**
    - **Action:** Manually navigated directly to `https://localhost:5173/` in the browser and accepted the security warning ("Proceed to localhost (unsafe)").
    - **Result:** This registered a security exception, allowing the browser to load assets from the Vite server.

---

### 5. Final Local Solution: The Vite Proxy for a Secure Context

- **Symptom:** The UI was finally working, but the QR scanner still failed because the main application page was served over `http://`.
- **Root Cause:** Browsers block camera access on insecure (`http://`) pages, regardless of where assets are loaded from.
- **Solution:**
    - **Action:** Configured Vite to act as an intelligent HTTPS proxy. All traffic goes to `https://localhost:5173`. Vite serves its own assets and forwards all other requests (for backend routes) to the `php artisan serve` process.
    - **Action:** Updated `APP_URL` in `.env` to `https://localhost:5173` to match this single entry point.
    - **Result:** The entire application is now served from a single, secure origin, resolving all CORS, mixed-content, and camera access issues for the local machine.

---

### 6. Cleaning Up: Silencing Sass Warnings

- **Symptom:** The `npm run dev` terminal was filled with deprecation warnings from the Bootstrap library.
- **Root Cause:** Bootstrap uses some older Sass syntax that is being phased out.
- **Solution:**
    - **Action:** Updated our own `resources/scss/bootstrap.scss` to use the modern `@use` syntax.
    - **Action:** Added the `quietDeps: true` option to the Sass preprocessor settings in `vite.config.js` to hide deprecation warnings originating from third-party libraries in `node_modules`.
    - **Result:** A clean and readable terminal output during development.

---

### 7. Final Outcome & Unresolved Issues

After multiple attempts to create a universally accessible development environment (via IP address and Cloudflare Tunnel), we repeatedly encountered a core issue where Vite, running inside the Distrobox environment, would incorrectly generate asset URLs using the invalid host `[::]`.

Advanced solutions (like Vite's proxy or dynamic URL generation via service providers) ultimately failed because they could not overcome this fundamental asset URL generation bug.

As a result, the project has been restored to the most stable configuration achieved:

- **Working Scenario:** The application UI is fully functional when accessed on the local machine at **`http://localhost:3001`**.
- **Configuration:** This state is achieved by using a specific setting in `vite.config.js` (`hmr: { host: 'localhost' }`) that forces asset URLs to use `localhost`, bypassing the `[::]` bug for local access only.

- **Known Limitations:**
    1.  **No Camera Access:** The QR Scanner will not work on `http://localhost:3001` because browsers require a secure `https://` context to grant camera permissions.
    2.  **No Network Access:** The UI will remain broken on other devices (e.g., phones on the same network) because their browsers cannot resolve the `localhost` in the asset paths.
    3.  **No Tunnel Access:** The Cloudflare Tunnel will not work for the same reason as network access.

const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '../../.env') });
const mysql = require('mysql2/promise');
const fs = require('fs');
const { exec } = require('child_process');
const util = require('util');
const crypto = require('crypto');
const execPromise = util.promisify(exec);

// --- SETTINGS ---
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
const API_BASE = 'https://localhost:8000';
const DEFAULT_PASSWORD = 'password';

// Parse command line arguments
// args: node seed.js [docs] [chunk_size] [concurrency]
let args = process.argv.slice(2);
if (args[0] === '--') {
    args = args.slice(1);
}

const DOCS_TO_CREATE = args[0] && !isNaN(parseInt(args[0], 10)) ? parseInt(args[0], 10) : 10000;
const CHUNK_SIZE = args[1] && !isNaN(parseInt(args[1], 10)) ? parseInt(args[1], 10) : 250;
const CONCURRENCY = args[2] && !isNaN(parseInt(args[2], 10)) ? parseInt(args[2], 10) : 50;

const delay = ms => new Promise(res => setTimeout(res, ms));

// Simple Cookie-aware HTTP Client for Form Data
class ApiClient {
    constructor() {
        this.cookie = '';
        this.csrfToken = '';
    }

    async initCsrf() {
        if (!this.csrfToken) {
            const res = await this.request('/');
            const html = await res.text();
            const match = html.match(/name="csrf_token" value="([^"]+)"/);
            if (match) {
                this.csrfToken = match[1];
            }
        }
    }

    async request(path, options = {}) {
        const headers = { ...options.headers };
        if (this.cookie) {
            headers['Cookie'] = this.cookie;
        }

        let retries = 3;
        while (retries > 0) {
            try {
                const response = await fetch(`${API_BASE}${path}`, {
                    ...options,
                    headers,
                    redirect: 'manual'
                });

                const setCookie = response.headers.get('set-cookie');
                if (setCookie) {
                    this.cookie = setCookie.split(';')[0];
                }

                return response;
            } catch (e) {
                retries--;
                if (retries === 0) throw e;
                await delay(500);
            }
        }
    }

    async postForm(path, data) {
        if (!this.csrfToken) await this.initCsrf();
        
        const params = new URLSearchParams();
        for (const key in data) {
            params.append(key, data[key]);
        }
        
        // Inject CSRF Token
        if (this.csrfToken) {
            params.append('csrf_token', this.csrfToken);
        }
        
        return this.request(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });
    }

    async login(email, password) {
        const res = await this.postForm('/login', { email, password });
        const location = res.headers.get('location');
        if (!location || location === '/login') {
            throw new Error(`Login failed for account "${email}". Received HTTP status ${res.status}, location: ${location || 'none'}`);
        }
    }
}

class ClientPool {
    constructor(email, password, poolSize = 10) {
        this.email = email;
        this.password = password;
        this.poolSize = poolSize;
        this.clients = [];
        this.currentIndex = 0;
    }

    async init() {
        for (let i = 0; i < this.poolSize; i++) {
            const client = new ApiClient();
            await client.login(this.email, this.password);
            this.clients.push(client);
        }
    }

    getClient() {
        const client = this.clients[this.currentIndex];
        this.currentIndex = (this.currentIndex + 1) % this.poolSize;
        return client;
    }
}

async function checkBackends() {
    try {
        await fetch(API_BASE);
        const { stdout } = await execPromise('ps aux | grep "[c]onsole.php"');
        if (!stdout.trim()) return false;
        return true;
    } catch (e) {
        return false;
    }
}

async function waitForBackends() {
    console.log('⏳ Checking backends...');
    while (true) {
        if (await checkBackends()) {
            console.log('✅ Backends online.');
            break;
        }
        console.log('⚠️ Backends not online. Retrying in 10s...');
        await delay(10000);
    }
}

function getWeightedPeakHour() {
    const weightedHours = [
        { hour: 8, weight: 5 },
        { hour: 9, weight: 25 },
        { hour: 10, weight: 25 },
        { hour: 11, weight: 5 },
        { hour: 12, weight: 5 },
        { hour: 13, weight: 20 },
        { hour: 14, weight: 5 },
        { hour: 15, weight: 20 },
        { hour: 16, weight: 15 }
    ];
    const totalWeight = 125;
    let rand = Math.floor(Math.random() * totalWeight) + 1;
    let current = 0;
    for (const item of weightedHours) {
        current += item.weight;
        if (rand <= current) return item.hour;
    }
    return 9;
}

function skipWeekend(dateObj, direction = 'forward') {
    let day = dateObj.getUTCDay(); // 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat
    if (day === 0 || day === 6) { // Sat (6), Sun (0) are non-working days
        let daysToMove = 0;
        if (direction === 'forward') {
            daysToMove = (day === 6) ? 2 : 1;
        } else {
            daysToMove = (day === 6) ? -1 : -2;
        }
        dateObj.setUTCDate(dateObj.getUTCDate() + daysToMove);
        dateObj.setUTCHours(getWeightedPeakHour() - 8, Math.floor(Math.random() * 60), Math.floor(Math.random() * 60), 0);
    }
    return dateObj;
}

function formatDates(dateObj) {
    const pad = n => n.toString().padStart(2, '0');
    const y = dateObj.getUTCFullYear();
    const m = pad(dateObj.getUTCMonth() + 1);
    const d = pad(dateObj.getUTCDate());
    const h = pad(dateObj.getUTCHours());
    const min = pad(dateObj.getUTCMinutes());
    const s = pad(dateObj.getUTCSeconds());
    return {
        iso: `${y}-${m}-${d}T${h}:${min}:${s}+08:00`,
        sql: `${y}-${m}-${d} ${h}:${min}:${s}`,
        hourKey: `${y}-${m}-${d} ${h}:00:00`,
        isBusinessHours: dateObj.getUTCHours() >= 8 && dateObj.getUTCHours() < 17 && dateObj.getUTCDay() !== 0 && dateObj.getUTCDay() !== 6
    };
}

let hourlyMetricsBuffer = {};
function generateMetrics(dates, isPeak = false) {
    const { hourKey, isBusinessHours } = dates;
    const baseConnections = isBusinessHours ? Math.floor(Math.random() * 41) + 10 : Math.floor(Math.random() * 9) + 2;
    const connections = isPeak ? baseConnections + Math.floor(Math.random() * 31) + 20 : baseConnections;
    const avgQueryTime = isPeak ? (Math.floor(Math.random() * 151) + 50) / 10 : (Math.floor(Math.random() * 46) + 5) / 10;
    const slowQueries = isPeak ? Math.floor(Math.random() * 4) : (Math.random() < 0.02 ? 1 : 0);

    if (!hourlyMetricsBuffer[hourKey]) {
        hourlyMetricsBuffer[hourKey] = { conns: [], avgTimes: [], slow: 0 };
    }
    hourlyMetricsBuffer[hourKey].conns.push(connections);
    hourlyMetricsBuffer[hourKey].avgTimes.push(avgQueryTime);
    hourlyMetricsBuffer[hourKey].slow += slowQueries;
}

function calculateLogHash(docId, userId, action, timestampStr, prevHash, stateHash, signature) {
    const uId = userId !== null ? userId : '';
    const data = [docId, uId, action, timestampStr, prevHash, stateHash, signature];
    return crypto.createHash('sha256').update(JSON.stringify(data)).digest('hex');
}

async function processDocumentAPI(i, deptPools, departmentNames, guestClient, purposesDb, districts) {
    const randomPurposeObj = purposesDb[Math.floor(Math.random() * purposesDb.length)];
    const randomDistrict = districts[Math.floor(Math.random() * districts.length)];
    const randomGuestDept = departmentNames[Math.floor(Math.random() * departmentNames.length)];

    // 1. GUEST SUBMIT
    const res = await guestClient.postForm('/submit-document', {
        guest_name: `Seeded Guest ${i}`,
        guest_email: `guest${i}@example.com`,
        guest_phone: '09123456789',
        district: randomDistrict,
        department: randomGuestDept,
        title: `Automated Test Document ${i}`,
        purpose_id: randomPurposeObj.id
    });

    const location = res.headers.get('location');
    if (!location) {
        const text = await res.text();
        console.error('Submit failed. Status:', res.status, 'Body:', text);
        throw new Error('Failed to extract location header');
    }

    const urlParams = new URLSearchParams(location.split('?')[1]);
    const trackingCode = urlParams.get('tracking_code');
    const documentId = urlParams.get('document_id');

    // Generate Route
    let route = [];
    if (randomPurposeObj.is_official && randomPurposeObj.suggested_route) {
        try {
            const parsed = JSON.parse(randomPurposeObj.suggested_route);
            if (Array.isArray(parsed) && parsed.length > 0) {
                route = parsed.map(p => typeof p === 'string' ? p : p.name);
            }
            
            // Replicate the UI logic: inject the guest's department at the front
            if (route.length === 0) {
                route = [randomGuestDept];
            } else if (route[0] !== randomGuestDept) {
                route.unshift(randomGuestDept);
            }
        } catch (e) {}
    }

    if (route.length === 0) {
        const routeCount = Math.floor(Math.random() * 3) + 2; // 2-4
        const shuffledDepts = [...departmentNames].sort(() => 0.5 - Math.random());
        route = shuffledDepts.slice(0, routeCount);
    }

    const fate = Math.random();
    const willBePending = fate < 0.10; // 10%
    const willBeDeclined = fate >= 0.10 && fate < 0.15; // 5%
    const willBeProcessing = fate >= 0.15 && fate < 0.30; // 15%
    const willBeReadyForRelease = fate >= 0.30 && fate < 0.40; // 10%
    const aimForReleased = fate >= 0.40; // 60%

    if (willBePending) return { id: documentId };

    // 2. RECORDS INTAKE
    const recordsClient = deptPools['Records Unit'].getClient();

    if (willBeDeclined) {
        const resDecline = await recordsClient.postForm(`/documents/decline`, {
            document_id: documentId,
            reason: 'Incomplete requirements or incorrect form.',
            pin: DEFAULT_PASSWORD
        });
        if (!resDecline.headers.get('location')) {
            throw new Error(`Decline failed for doc #${documentId}. Status: ${resDecline.status}`);
        }
        return { id: documentId };
    }

    const resFinalize = await recordsClient.postForm(`/documents/${documentId}/finalize`, {
        final_route: JSON.stringify(route),
        pin: DEFAULT_PASSWORD
    });
    if (!resFinalize.headers.get('location') || resFinalize.headers.get('location') === '/login') {
        throw new Error(`Finalize failed for doc #${documentId}. Status: ${resFinalize.status}, Location: ${resFinalize.headers.get('location')}`);
    }

    let actualStepsProcessed = 0;
    let stepsToSimulate = (aimForReleased || willBeReadyForRelease) ? route.length : Math.floor(Math.random() * route.length) + 1;

    // 3. DEPARTMENT PROCESSING
    for (let step = 0; step < route.length; step++) {
        if (step >= stepsToSimulate) break;

        const dept = route[step];
        const deptClient = deptPools[dept].getClient();

        // Scan the document to put it in processing
        const resScan = await deptClient.postForm('/documents/scan', { 
            tracking_code: trackingCode,
            pin: DEFAULT_PASSWORD
        });
        if (!resScan.headers.get('location') || resScan.headers.get('location') === '/login') {
            throw new Error(`Scan failed for doc #${documentId} by ${dept}. Status: ${resScan.status}, Location: ${resScan.headers.get('location')}`);
        }

        // If it's meant to be processing and we are at the last step, STOP before completing.
        if (willBeProcessing && step === stepsToSimulate - 1) {
            break;
        }

        const resComplete = await deptClient.postForm(`/tasks/${documentId}/complete`, { pin: DEFAULT_PASSWORD });
        if (!resComplete.headers.get('location') || resComplete.headers.get('location') === '/login') {
            throw new Error(`Task complete failed for doc #${documentId} by ${dept}. Status: ${resComplete.status}, Location: ${resComplete.headers.get('location')}`);
        }
        actualStepsProcessed++;
    }

    // 4. RECORDS FINAL RELEASE
    if (actualStepsProcessed === route.length && (aimForReleased || willBeReadyForRelease)) {
        const resRecScan = await recordsClient.postForm('/documents/scan', { 
            tracking_code: trackingCode,
            pin: DEFAULT_PASSWORD
        });
        if (!resRecScan.headers.get('location') || resRecScan.headers.get('location') === '/login') {
            throw new Error(`Final release scan failed for doc #${documentId}. Status: ${resRecScan.status}`);
        }

        if (aimForReleased) {
            const resRelComplete = await recordsClient.postForm(`/releasing/${documentId}/complete`, { pin: DEFAULT_PASSWORD });
            if (!resRelComplete.headers.get('location') || resRelComplete.headers.get('location') === '/login') {
                throw new Error(`Release complete failed for doc #${documentId}. Status: ${resRelComplete.status}`);
            }
        }
    }

    return { id: documentId };
}

async function timeTravelRetrofit(connection, documentIds) {
    if (documentIds.length === 0) return;

    const [logs] = await connection.query(`SELECT * FROM document_logs WHERE document_id IN (?) ORDER BY id ASC`, [documentIds]);
    const logsByDoc = {};
    logs.forEach(l => {
        if (!logsByDoc[l.document_id]) logsByDoc[l.document_id] = [];
        logsByDoc[l.document_id].push(l);
    });

    const queries = [];
    const updateParams = [];

    const refDate = new Date();
    refDate.setUTCHours(17, 0, 0, 0);

    for (const docId of documentIds) {
        const docLogs = logsByDoc[docId] || [];
        if (docLogs.length === 0) continue;

        const isRecent = Math.random() < 0.40;
        let cTime = new Date(refDate.getTime());
        if (isRecent) {
            cTime.setUTCDate(cTime.getUTCDate() - (Math.floor(Math.random() * 30) + 1));
        } else {
            cTime.setUTCFullYear(cTime.getUTCFullYear() - Math.floor(Math.random() * 5));
            cTime.setUTCDate(cTime.getUTCDate() - Math.floor(Math.random() * 365));
        }
        cTime.setUTCHours(Math.floor(Math.random() * 9) + 8);
        cTime.setUTCMinutes(Math.floor(Math.random() * 60));
        cTime = skipWeekend(cTime, 'backward');

        let previousHash = 'genesis_hash';
        let firstSqlDate = null;
        let lastSqlDate = null;

        for (let j = 0; j < docLogs.length; j++) {
            const log = docLogs[j];

            if (j > 0) {
                if (log.action.includes('Accepted')) {
                    cTime.setUTCMinutes(cTime.getUTCMinutes() + Math.floor(Math.random() * 5) + 1);
                } else if (log.action.includes('Received')) {
                    cTime.setUTCMinutes(cTime.getUTCMinutes() + Math.floor(Math.random() * 360) + 5);
                } else if (log.action.includes('Complete')) {
                    cTime.setUTCMinutes(cTime.getUTCMinutes() + Math.floor(Math.random() * 720) + 5);

                } else if (log.action.includes('Ready for Releasing')) {
                    cTime.setUTCMinutes(cTime.getUTCMinutes() + Math.floor(Math.random() * 30) + 5);
                } else if (log.action.includes('Released')) {
                    cTime.setUTCMinutes(cTime.getUTCMinutes() + Math.floor(Math.random() * 120) + 5);
                } else {
                    cTime.setUTCMinutes(cTime.getUTCMinutes() + Math.floor(Math.random() * 120) + 5);
                }
                cTime = skipWeekend(cTime, 'forward');
            }

            const dates = formatDates(cTime);
            if (j === 0) firstSqlDate = dates.sql;
            lastSqlDate = dates.sql;

            const newHash = calculateLogHash(log.document_id, log.user_id, log.action, dates.iso, previousHash, log.document_state_hash, log.signature);
            
            queries.push(`UPDATE document_logs SET created_at = ?, updated_at = ?, previous_hash = ?, hash = ? WHERE id = ?`);
            updateParams.push(dates.sql, dates.sql, previousHash, newHash, log.id);

            previousHash = newHash;

            const isPeak = log.action.includes('Declined');
            generateMetrics(dates, isPeak);
        }

        if (firstSqlDate) {
            let hasDeclined = docLogs.some(l => l.action.includes('Declined'));
            let hasReleased = docLogs.some(l => l.action.includes('Released'));
            if (hasDeclined) {
                queries.push(`UPDATE documents SET created_at = ?, updated_at = ?, declined_at = ? WHERE id = ?`);
                updateParams.push(firstSqlDate, lastSqlDate, lastSqlDate, docId);
            } else if (hasReleased) {
                queries.push(`UPDATE documents SET created_at = ?, updated_at = ?, released_at = ? WHERE id = ?`);
                updateParams.push(firstSqlDate, lastSqlDate, lastSqlDate, docId);
            } else {
                queries.push(`UPDATE documents SET created_at = ?, updated_at = ? WHERE id = ?`);
                updateParams.push(firstSqlDate, lastSqlDate, docId);
            }
        }
    }

    if (queries.length > 0) {
        let qIdx = 0;
        const promises = [];
        await connection.query('START TRANSACTION');
        try {
            for (let q of queries) {
                if (q.includes('document_logs')) {
                    promises.push(connection.query(q, [updateParams[qIdx], updateParams[qIdx+1], updateParams[qIdx+2], updateParams[qIdx+3], updateParams[qIdx+4]]));
                    qIdx += 5;
                } else if (q.includes('declined_at') || q.includes('released_at')) {
                    promises.push(connection.query(q, [updateParams[qIdx], updateParams[qIdx+1], updateParams[qIdx+2], updateParams[qIdx+3]]));
                    qIdx += 4;
                } else {
                    promises.push(connection.query(q, [updateParams[qIdx], updateParams[qIdx+1], updateParams[qIdx+2]]));
                    qIdx += 3;
                }
            }
            await Promise.all(promises);
            await connection.query('COMMIT');
        } catch (e) {
            await connection.query('ROLLBACK');
            throw e;
        }
    }
}

async function flushMetrics(connection) {
    if (Object.keys(hourlyMetricsBuffer).length === 0) return;
    
    console.log(`\n📊 Flushing database metrics to clear memory...`);
    const metricsToInsert = [];
    for (const hourKey in hourlyMetricsBuffer) {
        const data = hourlyMetricsBuffer[hourKey];
        const conns = data.conns.length > 0 ? data.conns.reduce((a, b) => a + b, 0) / data.conns.length : 0;
        const avg = data.avgTimes.length > 0 ? data.avgTimes.reduce((a, b) => a + b, 0) / data.avgTimes.length : 0;
        metricsToInsert.push([conns, avg, data.slow, hourKey]);
    }

    for (let i = 0; i < metricsToInsert.length; i += 1000) {
        const chunk = metricsToInsert.slice(i, i + 1000);
        await connection.query('INSERT INTO database_metrics (connections, avg_query_time_ms, slow_queries, created_at) VALUES ?', [chunk]);
    }
    hourlyMetricsBuffer = {}; // Free memory!
}

async function seed() {
    const startTime = Date.now();
    let connection;
    try {
        await waitForBackends();
        console.log('🔄 Starting advanced API-driven Time-Travel seed process...');

        connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USERNAME,
            password: process.env.DB_PASSWORD,
            database: process.env.DB_DATABASE
        });

        console.log('🧹 Cleaning tables...');
        await connection.query('SET FOREIGN_KEY_CHECKS = 0');
        await connection.query('TRUNCATE TABLE document_logs');
        await connection.query('TRUNCATE TABLE documents');
        await connection.query('TRUNCATE TABLE daily_department_metrics');
        await connection.query('TRUNCATE TABLE notifications');
        await connection.query('TRUNCATE TABLE report_jobs');
        await connection.query('TRUNCATE TABLE jobs');
        await connection.query('TRUNCATE TABLE failed_jobs');
        await connection.query('TRUNCATE TABLE database_metrics');
        await connection.query('TRUNCATE TABLE integrity_checks');
        await connection.query('TRUNCATE TABLE cache');
        await connection.query('TRUNCATE TABLE cache_locks');
        await connection.query('TRUNCATE TABLE user_public_key_histories');
        await connection.query('SET FOREIGN_KEY_CHECKS = 1');

        console.log('🧹 Cleaning file caches...');
        const cacheDir = path.join(__dirname, '../../cache');
        if (fs.existsSync(cacheDir)) {
            try {
                const { execSync } = require('child_process');
                execSync(`rm -rf "${cacheDir}/data"/* "${cacheDir}/responses"/* "${cacheDir}"/*.json`, { stdio: 'ignore' });
            } catch (e) {
                console.log('⚠️ Minor issue clearing file cache directory, proceeding anyway.');
            }
        }

        const [users] = await connection.query("SELECT u.email, d.name as dept_name FROM users u JOIN departments d ON u.department_id = d.id WHERE u.role IN ('staff', 'officer')");
        const deptMap = {};
        users.forEach(u => deptMap[u.dept_name] = u.email);
        const departmentNames = Object.keys(deptMap).filter(name => name !== 'Records Unit');

        const [purposesDb] = await connection.query("SELECT id, is_official, suggested_route FROM purposes");
        const districts = [
            'East I District', 'East II District', 
            'South I District', 'South II District', 
            'West I District', 'West II District', 
            'North I District', 'North II District', 'North III District', 
            'City Central District'
        ];

        console.log(`🚀 Processing ${DOCS_TO_CREATE} documents...`);

        console.log('🔐 Generating digital signatures (Ed25519) for seeded users...');
        const { execSync } = require('child_process');
        execSync(`php "${path.join(__dirname, '../generate-keys.php')}"`, { stdio: 'inherit' });

        console.log('🔑 Initializing API Client Pools to bypass login overhead...');
        const deptPools = {};
        for (const dept of Object.keys(deptMap)) {
            deptPools[dept] = new ClientPool(deptMap[dept], DEFAULT_PASSWORD, CONCURRENCY);
            await deptPools[dept].init();
        }
        console.log('✅ Client Pools initialized!');

        // 1. REUSE A SINGLE GUEST CLIENT
        // This prevents creating 500,000 individual PHP session files which causes
        // the PHP session Garbage Collector to completely crash disk I/O.
        const sharedGuestClient = new ApiClient();
        await sharedGuestClient.initCsrf(); // Grab CSRF token once

        let totalProcessed = 0;
        while (totalProcessed < DOCS_TO_CREATE) {
            const chunkAmount = Math.min(CHUNK_SIZE, DOCS_TO_CREATE - totalProcessed);
            const chunkDocIds = [];
            for (let i = 0; i < chunkAmount; i += CONCURRENCY) {
                const currentBatchSize = Math.min(CONCURRENCY, chunkAmount - i);
                const batchPromises = [];
                
                for (let j = 0; j < currentBatchSize; j++) {
                     batchPromises.push(processDocumentAPI(
                         totalProcessed + i + j + 1, 
                         deptPools, 
                         departmentNames, 
                         sharedGuestClient, 
                         purposesDb, 
                         districts
                     ));
                }
                
                const results = await Promise.all(batchPromises);
                chunkDocIds.push(...results.map(r => r.id));
            }

            await delay(1000); 

            await timeTravelRetrofit(connection, chunkDocIds);

            totalProcessed += chunkAmount;
            
            // 2. PERIODIC MEMORY FLUSHING
            // Flush metrics to DB every 5,000 documents to prevent Node.js V8 
            // array structures from leaking gigabytes of memory.
            if (totalProcessed % 5000 === 0) {
                await flushMetrics(connection);
            }
            
            // Print progress cleanly
            const memoryUsage = process.memoryUsage();
            const ramMB = Math.round(memoryUsage.rss / 1024 / 1024);
            process.stdout.write(`\r   ⏳ Progress: ${totalProcessed} / ${DOCS_TO_CREATE} documents time-traveled. | 🧠 RAM: ${ramMB} MB   `);
        }

        console.log('\n'); // newline after progress
        await flushMetrics(connection);

        console.log('📈 Backfilling daily departmental metrics...');
        execSync(`php "${path.join(__dirname, '../backfill-metrics.php')}"`, { stdio: 'inherit' });

        console.log('🔒 Resetting all digital signatures for first-time login...');
        await connection.query("UPDATE user_public_key_histories SET activated_at = '2020-01-01 00:00:00', deactivated_at = NOW(), updated_at = NOW() WHERE deactivated_at IS NULL");
        await connection.query("UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL");

        const endTime = Date.now();
        const elapsedSeconds = Math.floor((endTime - startTime) / 1000);
        const hours = Math.floor(elapsedSeconds / 3600);
        const minutes = Math.floor((elapsedSeconds % 3600) / 60);
        const seconds = elapsedSeconds % 60;
        
        const finalMem = process.memoryUsage();
        const finalRamMB = Math.round(finalMem.rss / 1024 / 1024);
        
        console.log('\n📊 --- SEEDING REPORT ---');
        console.log(`⏱️ Total Time Elapsed: ${hours}h ${minutes}m ${seconds}s`);
        console.log(`🧠 Final Memory (RSS): ${finalRamMB} MB`);
        console.log('🎉 Advanced Seeding completed successfully!');
        await connection.end();
        process.exit(0);
    } catch (error) {
        console.error('\n❌ Seeding failed:', error);
        if (connection) await connection.end();
        process.exit(1);
    }
}

seed();

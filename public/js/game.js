// 1. Initialize the map
const gameConfig = window.GAME_CONFIG || {};
const buildApiUrl = (path) => {
    if (typeof window.apiUrl === 'function') {
        return window.apiUrl(path);
    }
    const normalized = path.startsWith('/') ? path : `/${path}`;
    return `${window.Leuvenguesser?.basePath || ''}${normalized}`;
};
const urlParams = new URLSearchParams(window.location.search);
const selectedMode = urlParams.get('mode');
const selectedChallengeSetId = urlParams.get('challengeSetId');
const isLeuvenMode = !selectedChallengeSetId;
const leuvenCoords = [50.8798, 4.7005];
// Bounds roughly covering Leuven so it always stays visible
const leuvenBounds = L.latLngBounds(
    [50.85, 4.66],  // southwest
    [50.90, 4.74]   // northeast
);
const worldCoords = [20, 0];
const worldBounds = L.latLngBounds(
    [-85, -180],
    [85, 180]
);
const LEUVEN_INITIAL_ZOOM = 15;
const WORLD_INITIAL_ZOOM = 2;
const INITIAL_MAP_FOCUS_RATIO = 0.70;
const initialCoords = isLeuvenMode ? leuvenCoords : worldCoords;
const initialBounds = isLeuvenMode ? leuvenBounds : worldBounds;
const initialZoom = isLeuvenMode ? LEUVEN_INITIAL_ZOOM : WORLD_INITIAL_ZOOM;
const map = L.map('map', {
    zoomControl: false,
    minZoom: initialZoom,
    maxZoom: 19,
    maxBounds: initialBounds,
    maxBoundsViscosity: 1.0
}).setView(initialCoords, initialZoom);

L.control.zoom({ position: 'bottomright' }).addTo(map);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

// 2. Game Variables
let playerMarker = null;
let playerGuessLatLng = null;
let actualMarker = null;
let connectionLine = null;
let timerInterval = null;
let timeLeft = 30;
let currentRoundId = null;
const configuredApiFindDuelUrl = gameConfig.apiFindDuelUrl || buildApiUrl('/api/game/find_duel');
const configuredApiRoundUrl = gameConfig.apiRoundUrl || buildApiUrl('/api/game/round');
const configuredApiGuessUrl = gameConfig.apiGuessUrl || buildApiUrl('/api/game/guess');
const apiDailyFinishUrl = gameConfig.apiDailyFinishUrl || buildApiUrl('/api/game/daily/finish');
const apiDailyRankingsUrl = gameConfig.apiDailyRankingsUrl || buildApiUrl('/api/game/daily/rankings');
const apiChallengeFinishUrl = gameConfig.apiChallengeFinishUrl || buildApiUrl('/api/game/challenge/finish');
const apiChallengeRankingsUrl = gameConfig.apiChallengeRankingsUrl || buildApiUrl('/api/game/challenge/rankings');
const apiDuelFinishUrl = gameConfig.apiDuelFinishUrl || buildApiUrl('/api/game/duel_finish');
const apiPollDuelFinishUrl = gameConfig.apiPollDuelFinishUrl || buildApiUrl('/api/game/duel_finish_poll');
const imageBaseUrl = gameConfig.imageBaseUrl || '/images/';
const markerShadowUrl = gameConfig.markerShadowUrl || '/assets/leaflet/images/marker-shadow.png';
let resolvedApiRoundUrl = null;
let resolvedApiGuessUrl = null;
let resolvedApiFindDuelUrl = null;
let gameDuelId = null;
let myDuelScore = null;
let theirDuelScore = null;
let theirDuelName = null;

// Session state. Daily is fixed at 5 rounds; Classic challenge sets use up to 5 available images.
const DAILY_TOTAL_ROUNDS = 5;
const MAX_SESSION_ROUNDS = 5;
let totalRounds = DAILY_TOTAL_ROUNDS;
let currentRoundNumber = 0;
let roundScores = [];
let roundDetails = [];
let playedLocationIds = []; // tracks IDs seen this session to avoid repeats

// Use a red marker to reveal the actual answer after the round ends.
const redIcon = new L.Icon({
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
    shadowUrl: markerShadowUrl,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
});

// DOM Elements
const timeDisplay = document.getElementById('time-display');
const guessButton = document.getElementById('guess-button');
const timerBox = document.getElementById('timer-box');
const imagePanel = document.getElementById('image-panel');
const locationImage = document.getElementById('location-image');
const resultTopBar = document.getElementById('result-top-bar');
const resultBottomBar = document.getElementById('result-bottom-bar');
const nextRoundBtn = document.getElementById('next-round-btn');
const shareRoundResultBtn = document.getElementById('share-round-result-btn');
const shareGameResultBtn = document.getElementById('share-game-result-btn');
const resDistanceDisplay = document.getElementById('res-distance');
const resScoreDisplay = document.getElementById('res-score');
const roundIndicator = document.getElementById('round-indicator');
const pollingModal = document.querySelector("[data-polling-modal]");
const matchedModal = document.querySelector("[data-matched-modal]");
const matchedModalTitle = document.getElementById("matched-modal-title");
const finalScreen = document.getElementById('final-screen');
const exitGameBtn = document.getElementById('exit-game-btn');
const exitConfirmModal = document.getElementById('exit-confirm-modal');
const exitConfirmBtn = document.getElementById('exit-confirm-btn');
const exitCancelBtn = document.getElementById('exit-cancel-btn');
const homeUrl = gameConfig.homeUrl || '/';

if (locationImage) {
    locationImage.decoding = 'async';
    locationImage.fetchPriority = 'high';
}

function getTimeDisplayElement() {
    let display = document.getElementById('time-display');
    if (!display && timerBox) {
        timerBox.innerHTML = 'TIME LEFT: <span id="time-display">00:30</span>';
        display = document.getElementById('time-display');
    }
    return display;
}

function setTimerText(value) {
    const display = getTimeDisplayElement();
    if (display) {
        display.textContent = value;
    }
}

function showApiError(message, details) {
    timerBox.classList.remove('hidden');
    const suffix = details ? `: ${details}` : '';
    timerBox.textContent = message + suffix;
    timerBox.style.color = '#c40000';
    guessButton.disabled = true;
    console.error('[game] ' + message, details || '');
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function getStoredUser() {
    try {
        const raw = localStorage.getItem('lg_user');
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

async function resolveApiUrls() {
    if (resolvedApiRoundUrl && resolvedApiGuessUrl && resolvedApiFindDuelUrl) {
        return { roundUrl: resolvedApiRoundUrl, guessUrl: resolvedApiGuessUrl, findDuelUrl: resolvedApiFindDuelUrl };
    }

    resolvedApiRoundUrl = configuredApiRoundUrl;
    resolvedApiGuessUrl = configuredApiGuessUrl;
    resolvedApiFindDuelUrl = configuredApiFindDuelUrl;

    try {
        const res = await fetch(resolvedApiRoundUrl, { cache: 'no-store' });
        const data = await parseJsonResponse(res);
        if (res.ok && typeof data.success === 'boolean') {
            //console.log(resolvedApiFindDuelUrl);
            console.info('[game] API route resolved', {
                roundUrl: resolvedApiRoundUrl,
                guessUrl: resolvedApiGuessUrl,
                findDuelUrl: resolvedApiFindDuelUrl,
            });
            return { roundUrl: resolvedApiRoundUrl, guessUrl: resolvedApiGuessUrl, findDuelUrl: resolvedApiFindDuelUrl };
        }
    } catch (error){
        console.error(error);
        // Fall through to legacy probe list.
    }

    const baseCandidates = [
        window.Leuvenguesser?.basePath || '',
        '',
        '/index.php',
        '/public/index.php'
    ];
    const roundCandidates = baseCandidates.map((base) => `${base}/api/game/round`);
    const guessByRound = Object.fromEntries(
        baseCandidates.map((base) => [`${base}/api/game/round`, `${base}/api/game/guess`])
    );

    for (const roundUrl of [...new Set(roundCandidates)]) {
        const guessUrl = guessByRound[roundUrl] || configuredApiGuessUrl;
        try {
            const res = await fetch(roundUrl, { cache: 'no-store' });
            const data = await parseJsonResponse(res);
            if (res.ok && typeof data.success === 'boolean') {
                resolvedApiRoundUrl = roundUrl;
                resolvedApiGuessUrl = guessUrl;
                console.info('[game] API route resolved (fallback)', { roundUrl, guessUrl });
                return { roundUrl, guessUrl };
            }
        } catch {
            // Try next candidate.
        }
    }

    throw new Error('Unable to resolve API URL');
}

// 3. Map Interaction
map.on('click', function (e) {
    if (guessButton.classList.contains('hidden')) {
        return;
    }

    playerGuessLatLng = e.latlng;

    if (playerMarker !== null) {
        map.removeLayer(playerMarker);
    }

    playerMarker = L.marker(playerGuessLatLng).addTo(map).bindPopup('Your Guess');
    guessButton.disabled = false;
});

async function setRoundImage(round) {
    const base = imageBaseUrl.endsWith('/') ? imageBaseUrl : imageBaseUrl + '/';
    const imageUrl = typeof round.photoUrl === 'string' && round.photoUrl !== ''
        ? round.photoUrl
        : base + encodeURIComponent(round.photo);
    await preloadRoundImage(imageUrl);
    locationImage.src = imageUrl;
    locationImage.alt = 'Guess where this was taken!';
}

function preloadRoundImage(url) {
    return new Promise((resolve) => {
        const image = new Image();
        let settled = false;
        const finish = () => {
            if (!settled) {
                settled = true;
                resolve();
            }
        };

        window.setTimeout(finish, 3000);
        image.onload = finish;
        image.onerror = finish;
        image.decoding = 'async';
        image.fetchPriority = 'high';
        image.src = url;

        if (typeof image.decode === 'function') {
            image.decode().then(finish, finish);
        }
    });
}

function findDuelUrlWithUser(findDuelUrl, poll) {
    const params = new URLSearchParams();
    var user = getStoredUser();
    if(user&&user.id){
        params.set('userId', user.id);
    }
    if(poll!=null){
        params.set('poll', poll);
    }

    const qs = params.toString();
    return qs ? `${findDuelUrl}?${qs}` : findDuelUrl;
}

function findPollDuelResultUrlWithUser(pollDuelResultUrl) {
    const params = new URLSearchParams();
    var user = getStoredUser();
    if(user&&user.id){
        params.set('userId', user.id);
    }
    if(gameDuelId){
        params.set('duelId', gameDuelId);
    }

    const qs = params.toString();
    return qs ? `${pollDuelResultUrl}?${qs}` : pollDuelResultUrl;
}

function roundUrlWithMode(roundUrl, duelId) {
    const params = new URLSearchParams();
    var user = getStoredUser();
    if(duelId){
        params.set('duelId', duelId);
    }
    if(user&&user.id){
        params.set('userId', user.id);
    }
    if(selectedMode){
        params.set('mode', selectedMode);
    }
    if (selectedChallengeSetId) {
        params.set('challengeSetId', selectedChallengeSetId);
    }
    if (playedLocationIds.length > 0) {
        params.set('excludeIds', playedLocationIds.join(','));
    }
    const qs = params.toString();
    return qs ? `${roundUrl}?${qs}` : roundUrl;
}

function focusedBounds(bounds, focusCenter) {
    const southWest = bounds.getSouthWest();
    const northEast = bounds.getNorthEast();
    const center = focusCenter || bounds.getCenter();
    const halfLat = ((northEast.lat - southWest.lat) * INITIAL_MAP_FOCUS_RATIO) / 2;
    const halfLng = ((northEast.lng - southWest.lng) * INITIAL_MAP_FOCUS_RATIO) / 2;

    return L.latLngBounds(
        [center.lat - halfLat, center.lng - halfLng],
        [center.lat + halfLat, center.lng + halfLng]
    );
}

function applyRoundMapConfig(round) {
    if (!round.map || !round.map.center || !round.map.bounds) {
        map.setMinZoom(initialZoom);
        map.setMaxBounds(initialBounds);
        map.setView(initialCoords, initialZoom);
        return;
    }

    const southWest = round.map.bounds.southWest;
    const northEast = round.map.bounds.northEast;
    const bounds = L.latLngBounds(
        [southWest.lat, southWest.lng],
        [northEast.lat, northEast.lng]
    );
    const center = L.latLng(round.map.center.lat, round.map.center.lng);

    const isWideRegion = Math.abs(northEast.lng - southWest.lng) > 10 || Math.abs(northEast.lat - southWest.lat) > 10;
    map.setMinZoom(isWideRegion ? 2 : 10);
    map.setMaxBounds(bounds);
    map.fitBounds(focusedBounds(bounds, center), { padding: [0, 0] });
}

// Multi-round session helpers
function startNewRound(round) {
    const apiTotalRounds = Number(round?.session?.totalRounds);
    if (Number.isInteger(apiTotalRounds) && apiTotalRounds > 0) {
        totalRounds = Math.min(MAX_SESSION_ROUNDS, apiTotalRounds);
    }

    currentRoundNumber++;
    if (roundIndicator) {
        roundIndicator.textContent = `Round ${currentRoundNumber} / ${totalRounds}`;
        roundIndicator.classList.remove('hidden');
    }
}

function updateNextRoundButton() {
    if (currentRoundNumber >= totalRounds) {
        nextRoundBtn.textContent = 'See Results';
        return;
    }
    nextRoundBtn.textContent = `Next Round (${currentRoundNumber + 1}/${totalRounds})`;
}

async function pollMatchStatus(findDuelUrl) {
    console.log("polling for match");
    let shouldPoll = true;
    while (shouldPoll) {
        try {
            const response = await fetch(findDuelUrlWithUser(findDuelUrl, true), {cache: 'no-store'});
            const data = await response.json().catch(() => ({}));

            console.log('Match status:', data);

            if (data.status === 'matched') {
                shouldPoll = false;
                await flashMatchedModal(`Get ready to head against ${data.matched_with_name}!`);
                return data.match_id;
            }

            // wait 1 second before trying again
            await new Promise(resolve => setTimeout(resolve, 1000));
        } catch (error) {
            console.error('Polling error:', error);
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
    }
}

// 4. Load round from API
async function loadRound() {
    guessButton.disabled = true;
    currentRoundId = null;
    timerBox.style.color = '#333';
    setTimerText('00:30');

    let match_id = null;

    if(selectedMode&&selectedMode=="duel"){
        //display some stall modal
        openPollingModal();


        //start matchmaking
        const { findDuelUrl } = await resolveApiUrls();
        let status = "";
        let matched_with = 0;

        try{
            const res = await fetch(findDuelUrlWithUser(findDuelUrl, false), {cache: 'no-store'});
            const data = await res.json().catch(() => ({}));
            //console.log(data);
            status = data.status;
            matched_with = data.matched_with_name;
            match_id = data.match_id;
        }
        catch (error) {
            console.log(error);
        }

        // if matching (no other players yet), start polling
        if(status=="waiting"){
            match_id = await pollMatchStatus(findDuelUrl);
        }
        // else start immediately
        else if(status=="matched"){
            await flashMatchedModal(`Get ready to head against ${matched_with}!`);
        }

    }

    gameDuelId = match_id;
    closePollingModal();
   // console.log(match_id);

    try {
        const { roundUrl } = await resolveApiUrls();
        const res = await fetch(roundUrlWithMode(roundUrl, gameDuelId), { cache: 'no-store' });
        const data = await res.json().catch(() => ({}));
        //console.log(data);
        if (!res.ok || !data.success || typeof data.id !== 'number' || typeof data.photo !== 'string') {
            throw new Error('Failed to load API round');
        }

        currentRoundId = data.id;
        playedLocationIds.push(data.id); // exclude this location from future rounds
        startNewRound(data);
        await setRoundImage(data);
        applyRoundMapConfig(data);
    } catch (error) {
        showApiError('API error: /api/game/round unavailable');
        throw error;
    }
}

// 5. Trigger Result View
async function finishRound() {
    clearInterval(timerInterval);

    let distanceMeters = null;
    let score = 0;
    let actualLocation = null;

    if (currentRoundId === null) {
        showApiError('API error: round id missing');
        return;
    }

    try {
        const { guessUrl } = await resolveApiUrls();
        const body = { id: currentRoundId };
        if (selectedChallengeSetId) {
            body.challengeSetId = selectedChallengeSetId;
        }
        if (playerGuessLatLng) {
            body.lat = playerGuessLatLng.lat;
            body.lng = playerGuessLatLng.lng;
        }

        const res = await fetch(guessUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            cache: 'no-store'
        });
        const data = await parseJsonResponse(res);
        if (!res.ok || !data.success || !data.actual) {
            const err = typeof data.error === 'string' ? data.error : `HTTP ${res.status}`;
            throw new Error(err);
        }

        actualLocation = L.latLng(data.actual.lat, data.actual.lng);
        distanceMeters = typeof data.distanceMeters === 'number' ? data.distanceMeters : null;
        score = typeof data.score === 'number' ? data.score : 0;
    } catch (error) {
        const details = error instanceof Error ? error.message : '';
        showApiError('Guess failed', details);
        return;
    }

    if (actualLocation === null) {
        resDistanceDisplay.textContent = 'Error';
        resScoreDisplay.textContent = '0';
        showApiError('API error: invalid coordinates from backend');
        return;
    }

    roundScores.push(score);
    roundDetails.push({
        roundNumber: currentRoundNumber,
        locationId: currentRoundId,
        guessLat: playerGuessLatLng ? playerGuessLatLng.lat : null,
        guessLng: playerGuessLatLng ? playerGuessLatLng.lng : null,
        actualLat: actualLocation.lat,
        actualLng: actualLocation.lng,
        distanceMeters,
        score
    });
    updateNextRoundButton();

    if (playerGuessLatLng && distanceMeters !== null) {
        if (distanceMeters < 1000) {
            resDistanceDisplay.textContent = `${Math.round(distanceMeters)} m`;
        } else {
            resDistanceDisplay.textContent = `${(distanceMeters / 1000).toFixed(2)} km`;
        }
    } else {
        resDistanceDisplay.textContent = 'Time Out';
    }

    resScoreDisplay.textContent = score;

    timerBox.classList.add('hidden');
    guessButton.classList.add('hidden');
    imagePanel.classList.add('hidden');
    exitGameBtn.classList.add('hidden');

    resultTopBar.classList.remove('hidden');
    resultBottomBar.classList.remove('hidden');

    setTimeout(() => {
        map.invalidateSize();

        actualMarker = L.marker(actualLocation, { icon: redIcon }).addTo(map).bindPopup('Actual Location').openPopup();

        if (playerGuessLatLng) {
            playerMarker.openPopup();

            connectionLine = L.polyline([playerGuessLatLng, actualLocation], {
                color: 'black',
                dashArray: '10, 10',
                weight: 2
            }).addTo(map);

            map.fitBounds(connectionLine.getBounds(), { padding: [100, 100] });
        } else {
            map.setView(actualLocation, 16);
        }
    }, 100);
}

async function pollDuelResult(){
    console.log("polling for duel result");
    let shouldPoll = true;
    while (shouldPoll) {
        try {
            const response = await fetch(findPollDuelResultUrlWithUser(apiPollDuelFinishUrl), {cache: 'no-store'});
            const data = await response.json().catch(() => ({}));

            //console.log('Duel finish status:', data);

            if (data.status === 'finished') {
                shouldPoll = false;

                return data;
            }

            // wait 1 second before trying again
            await new Promise(resolve => setTimeout(resolve, 1000));
        } catch (error) {
            console.error('Polling error:', error);
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
    }
}

// 6. Final Screen
async function showFinalScreen() {
    const totalScore = roundScores.reduce((a, b) => a + b, 0);
    const maxScore = Math.max(1, roundScores.length) * 1000;

    document.querySelector('.final-title').textContent = isLeuvenMode
        ? 'Leuven Challenge Complete!'
        : 'World Challenge Complete!';
    document.getElementById('final-total-score').textContent = totalScore;
    document.getElementById('final-max-score').textContent = maxScore;

    const breakdownEl = document.getElementById('final-round-breakdown');
    breakdownEl.innerHTML = '';
    roundScores.forEach((s, i) => {
        const pill = document.createElement('div');
        pill.className = 'round-pill';
        pill.innerHTML = `<div class="round-num">Round ${i + 1}</div><div class="round-score">${s}</div>`;
        breakdownEl.appendChild(pill);
    });

    const pbBanner = document.getElementById('final-pb-banner');
    const rankingsSection = document.querySelector('.final-rankings');
    const rankingsEl = document.getElementById('final-rankings-list');

    if (rankingsSection) {
        rankingsSection.classList.remove('hidden');
    }

    const user = getStoredUser();
    if (!user || !user.id) {
        pbBanner.innerHTML = 'Log in to save scores and view rankings.';
        pbBanner.className = 'pb-banner pb-existing';
        rankingsEl.innerHTML = '<div class="ranking-empty">Rankings are available after login.</div>';
        resultTopBar.classList.add('hidden');
        resultBottomBar.classList.add('hidden');
        finalScreen.classList.remove('hidden');
        return;
    }

    //Multiplayer mode, its own finish url, save result to duel
    if(selectedMode&&selectedMode=="duel"&&gameDuelId!=null){
        //Stall screen
        openPollingModal();

        const duelFinishUrl = apiDuelFinishUrl;
        const duelFinishBody = {
            userId: user.id,
            rounds: roundDetails,
            duelId: gameDuelId
        };
        //Push data to server
        let status = null;
        let resultData = null;
        try{
            const saveRes = await fetch(duelFinishUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(duelFinishBody),
                cache: 'no-store'
            });
            const data = await saveRes.json().catch(() => ({}));
            status = data.status;
            resultData = data;
        }
        catch (e) {

        }
        //Stall if other player not done
        if(status=="waiting"){
            resultData = await pollDuelResult();
        }
        //Finish if they're waiting
        else if(status=="finished"){

        }
        //Display rankings
        //console.log(resultData);

        closePollingModal();

        if (resultData!=null) {
            const medals = ['🥇', '🥈', '🥉'];

            //console.log(resultData['P1Name']);

            const ranks =
            [{
                userId: resultData['P1'],
                score: resultData['P1Score'],
                name: resultData['P1Name']
            },
                {
                userId: resultData['P2'],
                score: resultData['P2Score'],
                name: resultData['P2Name']
            }];

            if(resultData['isP1']==true){
                myDuelScore = resultData['P1Score'];
                theirDuelScore = resultData['P2Score'];
                theirDuelName = resultData['P2Name'];
            }
            else{
                myDuelScore = resultData['P2Score'];
                theirDuelScore = resultData['P1Score'];
                theirDuelName = resultData['P1Name'];
            }

            //console.log(ranks);

            ranks.sort((a, b) => b.score - a.score);

            //console.log(ranks);

            rankingsEl.innerHTML = ranks.map((r) => {
                const rank = ranks.indexOf(r);
                const medal = rank <= 3
                    ? `<span class="ranking-medal">${medals[rank]}</span>`
                    : `<span class="ranking-medal ranking-rank">#${rank}</span>`;
                return `<div class="ranking-row${rank <= 3 ? ' is-top-3' : ''}">${medal}<span class="ranking-name">${escapeHtml(r.name)}</span><span class="ranking-score">${r.score}</span></div>`;
            }).join('');

        }
        else {
            rankingsEl.innerHTML = '<div class="ranking-empty">No scores yet — you might be first!</div>';
        }

        //Multiplayer mode
    }
    else{
        //Singleplayer mode

        const finishUrl = isLeuvenMode ? apiDailyFinishUrl : apiChallengeFinishUrl;
        const rankingsUrl = isLeuvenMode
            ? apiDailyRankingsUrl
            : `${apiChallengeRankingsUrl}?challengeSetId=${encodeURIComponent(selectedChallengeSetId)}`;
        const finishBody = {
            userId: user.id,
            rounds: roundDetails
        };
        if (!isLeuvenMode) {
            finishBody.challengeSetId = selectedChallengeSetId;
        }

        // Save result to backend and handle personal best
        try {
            const saveRes = await fetch(finishUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(finishBody),
                cache: 'no-store'
            });
            const saveData = await saveRes.json().catch(() => ({}));

            if (saveData.success) {
                if (saveData.isPersonalBest) {
                    const prevText = saveData.previousBest !== null
                        ? ` <span class="pb-prev">Previous: ${saveData.previousBest}</span>`
                        : '';
                    pbBanner.innerHTML = `🏆 <strong>New Personal Best!</strong>${prevText}`;
                    pbBanner.className = 'pb-banner pb-new-record';
                } else if (saveData.previousBest !== null) {
                    pbBanner.innerHTML = `Your personal best: <strong>${saveData.previousBest}</strong>`;
                    pbBanner.className = 'pb-banner pb-existing';
                }
            }
        } catch { /* rankings still load even if save fails */ }

        // Load rankings
        rankingsEl.innerHTML = '<div class="ranking-loading">Loading…</div>';
        try {
            const res = await fetch(rankingsUrl, { cache: 'no-store' });
            const data = await res.json().catch(() => ({}));
            if (data.success && Array.isArray(data.rankings) && data.rankings.length > 0) {
                const medals = ['🥇', '🥈', '🥉'];
                rankingsEl.innerHTML = data.rankings.map((r) => {
                    const medal = r.rank <= 3
                        ? `<span class="ranking-medal">${medals[r.rank - 1]}</span>`
                        : `<span class="ranking-medal ranking-rank">#${r.rank}</span>`;
                    return `<div class="ranking-row${r.rank <= 3 ? ' is-top-3' : ''}">${medal}<span class="ranking-name">${escapeHtml(r.name)}</span><span class="ranking-score">${r.score}</span></div>`;
                }).join('');
            } else {
                rankingsEl.innerHTML = '<div class="ranking-empty">No scores yet — you might be first!</div>';
            }
        } catch {
            rankingsEl.innerHTML = '<div class="ranking-error">Could not load rankings.</div>';
        }
    }
    // Singleplayer mode

    exitGameBtn.classList.add('hidden');
    resultTopBar.classList.add('hidden');
    resultBottomBar.classList.add('hidden');
    finalScreen.classList.remove('hidden');
}

// 7. Event Listeners
function openPollingModal() {
    pollingModal.classList.remove('hidden');
    document.body.style.overflow = "hidden";
}

function closePollingModal() {
    pollingModal.classList.add('hidden');
    document.body.style.overflow = "";
}

async function flashMatchedModal(message){
    matchedModal.classList.remove('hidden');
    matchedModalTitle.textContent = message;

    return new Promise(resolve => setTimeout(()=>{
        matchedModal.classList.add('hidden');
        matchedModalTitle.textContent = "";
        resolve();
    }, 3000));
}

guessButton.addEventListener('click', () => {
    finishRound();
});

exitGameBtn.addEventListener('click', () => {
    exitConfirmModal.classList.remove('hidden');
});

exitCancelBtn.addEventListener('click', () => {
    exitConfirmModal.classList.add('hidden');
});

exitConfirmBtn.addEventListener('click', () => {
    window.location.href = homeUrl;
});

exitConfirmModal.addEventListener('click', (e) => {
    if (e.target === exitConfirmModal) {
        exitConfirmModal.classList.add('hidden');
    }
});

nextRoundBtn.addEventListener('click', async function () {
    if (currentRoundNumber >= totalRounds) {
        await showFinalScreen();
        return;
    }

    if (playerMarker) map.removeLayer(playerMarker);
    if (actualMarker) map.removeLayer(actualMarker);
    if (connectionLine) map.removeLayer(connectionLine);

    playerMarker = null;
    playerGuessLatLng = null;
    currentRoundId = null;
    guessButton.disabled = true;

    resultTopBar.classList.add('hidden');
    resultBottomBar.classList.add('hidden');
    timerBox.classList.remove('hidden');
    guessButton.classList.remove('hidden');
    imagePanel.classList.remove('hidden');
    exitGameBtn.classList.remove('hidden');

    setTimeout(() => {
        map.invalidateSize();
        map.setView(initialCoords, initialZoom);
    }, 100);

    try {
        await loadRound();
        startTimer();
    } catch {
        guessButton.disabled = true;
    }
});

shareRoundResultBtn.addEventListener('click', async function () {
    if(roundScores.length <= 0) {
        return;
    }
    var cpyStr = `I have just guessed a location in Leuvenguessr with a score of ${roundScores.at(roundScores.length-1)}/1000. Come join me at https://a25sewt408.studev.groept.be/public/`;
    //console.log(cpyStr);
    navigator.clipboard.writeText(cpyStr).then(
        () => {
            /* clipboard successfully set */
            shareRoundResultBtn.textContent = "Copied to clipboard";
            setTimeout(() => {
                shareRoundResultBtn.textContent = "Share";
            }, 1000);
        },
        () => {
            /* clipboard write failed */
            shareRoundResultBtn.textContent = "Error copying";
            setTimeout(() => {
                shareRoundResultBtn.textContent = "Share";
            }, 1000);
        },
    );
});

shareGameResultBtn.addEventListener('click', async function () {
    if(roundScores.length <= 0) {
        return;
    }
    const totalScore = roundScores.reduce((a, b) => a + b, 0);
    const maxScore = Math.max(1, roundScores.length) * 1000;

    var cpyStr = `I have just completed the daily challenge in Leuvenguessr with a score of ${totalScore}/${maxScore}. Come join me at https://a25sewt408.studev.groept.be/public/`;
    //console.log(cpyStr);

    if(selectedMode&&selectedMode=="duel"&&gameDuelId!=null){
        if(myDuelScore > theirDuelScore){
            cpyStr = `I have just bested ${theirDuelName} in Leuvenguessr with a score of ${myDuelScore} to ${theirDuelScore}. Come join me at https://a25sewt408.studev.groept.be/public/`;
        }
        else{
            cpyStr = `I just lost to ${theirDuelName} in Leuvenguessr with a score of ${myDuelScore} to ${theirDuelScore}. Come join me at https://a25sewt408.studev.groept.be/public/`;
        }
    }

    navigator.clipboard.writeText(cpyStr).then(
        () => {
            /* clipboard successfully set */
            shareGameResultBtn.textContent = "Copied to clipboard";
            setTimeout(() => {
                shareGameResultBtn.textContent = "Share";
            }, 1000);
        },
        () => {
            /* clipboard write failed */
            shareGameResultBtn.textContent = "Error copying";
            setTimeout(() => {
                shareGameResultBtn.textContent = "Share";
            }, 1000);
        },
    );
});



// 8. Timer Logic
function startTimer() {
    setTimerText('00:30');
    timeLeft = 30;

    timerInterval = setInterval(() => {
        timeLeft--;
        const seconds = timeLeft % 60;
        setTimerText(`00:${seconds.toString().padStart(2, '0')}`);

        if (timeLeft <= 0) {
            finishRound();
        }
    }, 1000);
}

(async function init() {
    try {
        await loadRound();
        startTimer();
    } catch {
        guessButton.disabled = true;
    }
})();

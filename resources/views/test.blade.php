<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <title>Tester Offering + Login (Multi Upload)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    :root{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif}
    body{margin:24px; background:#0b0b0b; color:#eaeaea}
    h1{margin:0 0 16px}
    .card{border:1px solid #222; border-radius:12px; padding:16px; margin:12px 0; background:#111}
    label{display:block; font-size:14px; margin:8px 0 6px}
    input[type="text"],input[type="email"],input[type="password"],input[type="number"],textarea{width:100%; padding:10px; border-radius:8px; border:1px solid #333; background:#0e0e0e; color:#eaeaea}
    textarea{min-height:120px; font-family:ui-monospace,Menlo,Consolas,monospace}
    .row{display:grid; grid-template-columns:1fr 1fr; gap:12px}
    .row-3{display:grid; grid-template-columns:repeat(3,1fr); gap:12px}
    .btn{display:inline-block; padding:10px 14px; border:1px solid #444; border-radius:10px; background:#1a1a1a; color:#fff; cursor:pointer}
    .btn:hover{background:#222}
    .muted{opacity:.8; font-size:12px}
    .tabs{display:flex; gap:8px; margin:6px 0 12px}
    .tab-btn{padding:8px 12px; border:1px solid #333; border-radius:999px; background:#121212; cursor:pointer}
    .tab-btn.active{background:#2a2a2a}
    .hidden{display:none}
    pre{background:#0e0e0e; border:1px solid #222; padding:12px; border-radius:8px; overflow:auto; max-height:50vh}
    .pill{font-size:12px; padding:2px 8px; border:1px solid #333; border-radius:999px; background:#151515}
    .flex{display:flex; gap:8px; align-items:center; flex-wrap:wrap}
    .right{margin-left:auto}
    .badge{font-size:12px; padding:2px 6px; border:1px solid #333; border-radius:6px}

    /* drag drop */
    .drop{border:2px dashed #3a3a3a; border-radius:12px; padding:14px; text-align:center; background:#0f0f0f}
    .drop.dragover{border-color:#6ea8fe; background:#121826}
    .thumbs{display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; margin-top:10px}
    .thumb{position:relative; border:1px solid #2a2a2a; border-radius:10px; overflow:hidden; background:#0b0b0b}
    .thumb img{display:block; width:100%; height:100px; object-fit:cover}
    .thumb .name{font-size:12px; padding:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; border-top:1px solid #222}
    .thumb .remove{position:absolute; top:6px; right:6px; background:#000a; border:1px solid #333; color:#fff; padding:2px 6px; border-radius:8px; cursor:pointer; font-size:12px}
    .progress{height:8px; background:#1f1f1f; border-radius:6px; overflow:hidden; margin-top:8px; border:1px solid #2a2a2a}
    .bar{height:100%; width:0%; background:#6ea8fe}
  </style>
</head>
<body>
  <h1>Tester Offering <span class="pill">Login + PATCH /api/offerings/{id}</span></h1>

  <!-- BASE -->
  <div class="card">
    <div class="row">
      <div>
        <label>Base URL (tanpa trailing slash)</label>
        <input id="baseUrl" type="text" value="http://127.0.0.1:8000" />
      </div>
      <div>
        <label>Offering ID</label>
        <input id="offeringId" type="text" placeholder="contoh: 6f72eba8-8d90-4928-8602-4e8eb24b3462 / 123" />
      </div>
    </div>
  </div>

  <!-- LOGIN -->
  <div class="card">
    <div class="flex">
      <h3 style="margin:0">Login</h3>
      <span class="badge">POST /api/login</span>
      <span id="loginStatus" class="right muted">Belum login</span>
    </div>
    <div class="row">
      <div>
        <label>Email</label>
        <input id="loginEmail" type="email" placeholder="email@example.com" />
      </div>
      <div>
        <label>Password</label>
        <div class="flex" style="gap:6px">
          <input id="loginPassword" type="password" placeholder="••••••••" style="flex:1" />
          <button id="togglePwd" class="btn" type="button" title="Show/Hide">👁</button>
        </div>
      </div>
    </div>
    <div class="flex" style="margin-top:10px">
      <label style="display:flex; align-items:center; gap:8px; margin:0">
        <input id="rememberSess" type="checkbox" />
        <span>Simpan token di sessionStorage</span>
      </label>
      <span class="muted">Hanya di tab ini, akan hilang saat tab ditutup.</span>
    </div>
    <div class="flex" style="margin-top:10px">
      <button id="btnLogin" class="btn" type="button">Login</button>
      <button id="btnLogout" class="btn" type="button">Logout</button>
    </div>

    <div id="userBox" class="muted" style="margin-top:8px"></div>

    <label style="margin-top:12px">Bearer Token</label>
    <input id="token" type="text" placeholder="Terisi otomatis setelah login" />
    <div class="muted">Header yang dipakai: <code>Authorization: Bearer &lt;token&gt;</code></div>
  </div>

  <!-- UPDATE AREA -->
  <div class="card">
    <div class="tabs">
      <button id="tabJson" class="tab-btn active" type="button">Mode JSON</button>
      <button id="tabForm" class="tab-btn" type="button">Mode Form-Data (upload file)</button>
    </div>

    <!-- MODE JSON -->
    <div id="jsonMode">
      <label>JSON Body</label>
      <textarea id="jsonBody">{
  "name_offer": "Bridal Makeup (Edited)",
  "price": 1500000,
  "makeup_type": "Party",
  "collaboration": null,
  "collaboration_price": null,
  "offer_pictures": [
    "/storage/offering/a.jpg",
    "/storage/offering/b.jpg"
  ],
  "add_ons": ["Hairdo","Retouch 2 jam"]
}</textarea>
      <div style="display:flex; gap:8px; margin-top:10px">
        <button id="sendJson" class="btn" type="button">Kirim PATCH (JSON)</button>
        <button id="prettyJson" class="btn" type="button">Rapikan JSON</button>
      </div>
    </div>

    <!-- MODE FORM-DATA -->
    <div id="formMode" class="hidden">
      <div class="row">
        <div>
          <label>name_offer</label>
          <input id="fd_name_offer" type="text" placeholder="cth: Engagement Makeup" />
        </div>
        <div>
          <label>price</label>
          <input id="fd_price" type="number" step="0.01" placeholder="cth: 1200000" />
        </div>
      </div>

      <div class="row-3">
        <div>
          <label>makeup_type</label>
          <input id="fd_makeup_type" type="text" placeholder="cth: Bridal/Party" />
        </div>
        <div>
          <label>collaboration</label>
          <input id="fd_collab" type="text" placeholder="cth: Vendor X (atau kosongkan)" />
        </div>
        <div>
          <label>collaboration_price</label>
          <input id="fd_collab_price" type="number" step="0.01" placeholder="cth: 250000 (atau kosongkan)" />
        </div>
      </div>

      <div class="row">
        <div>
          <label>offer_pictures (teks, pisahkan dengan koma)</label>
          <input id="fd_offer_pictures" type="text" placeholder="/storage/offering/a.jpg, /storage/offering/b.jpg" />
        </div>
        <div>
          <label>add_ons (teks, pisahkan dengan koma)</label>
          <input id="fd_add_ons" type="text" placeholder="Hairdo, Retouch 2 jam" />
        </div>
      </div>

      <!-- NEW: DRAG & DROP MULTI FILE -->
      <div>
        <label>Upload Gambar (offer_image / offer_images[])</label>
        <div id="drop" class="drop">
          <div>Tarik & letakkan file di sini atau klik untuk memilih</div>
          <input id="fileInput" type="file" accept="image/*" multiple style="display:none" />
        </div>
        <div class="muted" style="margin-top:6px">
          • Klik area di atas untuk memilih beberapa file<br>
          • Atau drag & drop banyak file sekaligus<br>
          • Kamu bisa hapus file sebelum kirim
        </div>
        <div id="thumbs" class="thumbs"></div>
        <div class="progress" id="progWrap" style="display:none"><div id="bar" class="bar"></div></div>
      </div>

      <div style="margin-top:10px">
        <button id="sendForm" class="btn" type="button">Kirim PATCH (Form-Data)</button>
      </div>
    </div>
  </div>

  <!-- PREVIEW -->
  <div class="card">
    <label>Request Preview</label>
    <pre id="reqPreview">–</pre>
    <label>Response</label>
    <pre id="resBox">–</pre>
  </div>

  <script>
    const $ = (id) => document.getElementById(id);

    // base & auth
    const baseUrl = $('baseUrl');
    const offeringId = $('offeringId');
    const token = $('token');

    // login
    const loginEmail = $('loginEmail');
    const loginPassword = $('loginPassword');
    const btnLogin = $('btnLogin');
    const btnLogout = $('btnLogout');
    const togglePwd = $('togglePwd');
    const rememberSess = $('rememberSess');
    const loginStatus = $('loginStatus');
    const userBox = $('userBox');

    // tabs
    const tabJson = $('tabJson');
    const tabForm = $('tabForm');
    const jsonMode = $('jsonMode');
    const formMode = $('formMode');

    // json
    const jsonBody = $('jsonBody');
    const sendJson = $('sendJson');
    const prettyJson = $('prettyJson');

    // form-data + multi upload
    const sendForm = $('sendForm');
    const fd_name_offer = $('fd_name_offer');
    const fd_price = $('fd_price');
    const fd_makeup_type = $('fd_makeup_type');
    const fd_collab = $('fd_collab');
    const fd_collab_price = $('fd_collab_price');
    const fd_offer_pictures = $('fd_offer_pictures');
    const fd_add_ons = $('fd_add_ons');

    const drop = $('drop');
    const fileInput = $('fileInput');
    const thumbs = $('thumbs');
    const progWrap = $('progWrap');
    const bar = $('bar');

    // req/resp
    const reqPreview = $('reqPreview');
    const resBox = $('resBox');

    // state: selected files
    let files = []; // array of File

    // helpers
    const getBase = () => baseUrl.value.replace(/\/+$/, '');
    const setStatus = (txt) => loginStatus.textContent = txt;

    function showRequestPreview({ method, url, headers, body }) {
      const safeHeaders = { ...headers };
      if (safeHeaders.Authorization) safeHeaders.Authorization = 'Bearer ***masked***';
      let text = method + ' ' + url + '\n\nHeaders:\n' + JSON.stringify(safeHeaders, null, 2);
      if (body instanceof FormData) {
        const obj = {};
        body.forEach((v, k) => {
          obj[k] = (v instanceof File) ? `(File: ${v.name}, ${v.size}B, ${v.type || 'application/octet-stream'})` : v;
        });
        text += '\n\nFormData:\n' + JSON.stringify(obj, null, 2);
      } else if (typeof body === 'string') {
        text += '\n\nBody:\n' + body;
      }
      reqPreview.textContent = text;
    }

    async function doFetch({ method, url, headers, body, onProgress }) {
      showRequestPreview({ method, url, headers, body });

      // gunakan XHR agar bisa progress multipart
      if (body instanceof FormData && typeof onProgress === 'function') {
        return new Promise((resolve) => {
          const xhr = new XMLHttpRequest();
          xhr.open(method, url, true);
          for (const [k, v] of Object.entries(headers || {})) {
            xhr.setRequestHeader(k, v);
          }
          xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
          };
          xhr.onreadystatechange = () => {
            if (xhr.readyState === 4) {
              const ct = xhr.getResponseHeader('content-type') || '';
              let data = xhr.responseText;
              try { if (ct.includes('application/json')) data = JSON.parse(xhr.responseText); } catch{}
              resBox.textContent = `HTTP ${xhr.status}\n\n` + (typeof data === 'string' ? data : JSON.stringify(data, null, 2));
              resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data });
            }
          };
          xhr.send(body);
        });
      }

      // fallback fetch (JSON mode)
      try {
        const res = await fetch(url, { method, headers, body });
        const contentType = res.headers.get('content-type') || '';
        let data;
        if (contentType.includes('application/json')) {
          data = await res.json();
          resBox.textContent = `HTTP ${res.status}\n\n` + JSON.stringify(data, null, 2);
        } else {
          const text = await res.text();
          resBox.textContent = `HTTP ${res.status}\n\n` + text;
        }
        return { ok: res.ok, status: res.status, data };
      } catch (err) {
        resBox.textContent = 'Request error: ' + err.message;
        return { ok: false, error: err };
      }
    }

    function applyToken(t) {
      token.value = t || '';
      setStatus(t ? 'Login OK (token tersimpan)' : 'Belum login');
    }
    function saveSessionToken(t) {
      if (rememberSess.checked && t) sessionStorage.setItem('tester_token', t);
      else sessionStorage.removeItem('tester_token');
    }
    function loadSessionToken() {
      const t = sessionStorage.getItem('tester_token');
      if (t) { token.value = t; setStatus('Token dari sessionStorage'); }
    }
    function setUserBox(user, profile) {
      if (!user) { userBox.textContent = ''; return; }
      const lines = [];
      if (user.id) lines.push(`user.id: ${user.id}`);
      if (user.email) lines.push(`user.email: ${user.email}`);
      if (profile && profile.id) lines.push(`profile.id: ${profile.id}`);
      if (profile && profile.role) lines.push(`profile.role: ${profile.role}`);
      userBox.textContent = lines.join(' | ');
    }

    // tabs
    tabJson.onclick = () => { tabJson.classList.add('active'); tabForm.classList.remove('active'); jsonMode.classList.remove('hidden'); formMode.classList.add('hidden'); resBox.textContent='–'; reqPreview.textContent='–'; };
    tabForm.onclick = () => { tabForm.classList.add('active'); tabJson.classList.remove('active'); formMode.classList.remove('hidden'); jsonMode.classList.add('hidden'); resBox.textContent='–'; reqPreview.textContent='–'; };

    // login UI
    togglePwd.onclick = () => loginPassword.type = (loginPassword.type === 'password' ? 'text' : 'password');
    btnLogin.onclick = async () => {
      const email = loginEmail.value.trim();
      const pwd = loginPassword.value;
      if (!email || !pwd) return alert('Isi email & password');

      const res = await doFetch({
        method: 'POST',
        url: `${getBase()}/api/auth/login`,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email, password: pwd })
      });

      if (res.ok && res.data && res.data.token) {
        applyToken(res.data.token);
        saveSessionToken(res.data.token);
        setUserBox(res.data.user, res.data.profile);
      } else {
        setStatus(`Login gagal (${res.status || 'ERR'})`);
        setUserBox(null, null);
      }
    };
    btnLogout.onclick = () => { applyToken(''); saveSessionToken(''); setUserBox(null, null); resBox.textContent = 'Logout: token dibersihkan'; };

    // JSON mode
    prettyJson.onclick = () => { try { jsonBody.value = JSON.stringify(JSON.parse(jsonBody.value), null, 2); } catch (e) { alert('JSON tidak valid: ' + e.message); } };
    sendJson.onclick = () => {
      const id = offeringId.value.trim();
      const base = getBase();
      if (!id) return alert('Isi Offering ID dulu');
      if (!token.value.trim()) return alert('Isi atau dapatkan token lewat Login');

      let payload;
      try { payload = JSON.stringify(JSON.parse(jsonBody.value)); }
      catch (e) { return alert('JSON tidak valid: ' + e.message); }

      doFetch({
        method: 'PATCH',
        url: `${base}/api/offerings/${encodeURIComponent(id)}`,
        headers: { 'Authorization': `Bearer ${token.value.trim()}`, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: payload
      });
    };

    // ===== MULTI UPLOAD =====
    // open file dialog on click
    drop.addEventListener('click', () => fileInput.click());
    // drag styles
    ;['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); drop.classList.add('dragover'); }));
    ;['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); drop.classList.remove('dragover'); }));
    // handle drop
    drop.addEventListener('drop', (e) => {
      const list = Array.from(e.dataTransfer.files || []);
      addFiles(list);
    });
    // handle file input
    fileInput.addEventListener('change', () => addFiles(Array.from(fileInput.files || [])));

    function addFiles(list){
      const imgs = list.filter(f => /^image\//i.test(f.type));
      files = files.concat(imgs);
      renderThumbs();
    }
    function removeFile(idx){
      files.splice(idx,1);
      renderThumbs();
    }
    function renderThumbs(){
      thumbs.innerHTML = '';
      files.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        const wrap = document.createElement('div'); wrap.className = 'thumb';
        const img = document.createElement('img'); img.src = url; img.alt = f.name;
        const rm = document.createElement('button'); rm.className='remove'; rm.textContent='Hapus'; rm.onclick=()=>removeFile(i);
        const name = document.createElement('div'); name.className='name'; name.textContent = f.name;
        wrap.appendChild(img); wrap.appendChild(rm); wrap.appendChild(name);
        thumbs.appendChild(wrap);
      });
    }

    function showProgress(pct){
      progWrap.style.display = 'block';
      bar.style.width = (pct||0) + '%';
      if (pct >= 100) setTimeout(() => { progWrap.style.display='none'; bar.style.width='0%'; }, 600);
    }

    // send Form-Data
    sendForm.onclick = async () => {
    const id = offeringId.value.trim();
    const base = getBase();
    if (!id) return alert('Isi Offering ID dulu');
    if (!token.value.trim()) return alert('Isi atau dapatkan token lewat Login');

    const fd = new FormData();
    // <-- method spoofing untuk Laravel
    fd.append('_method', 'PATCH');

    const v = (el) => el.value.trim();
    if (v(fd_name_offer))   fd.append('name_offer', v(fd_name_offer));
    if (v(fd_price))        fd.append('price', v(fd_price));
    if (v(fd_makeup_type))  fd.append('makeup_type', v(fd_makeup_type));
    const collab = v(fd_collab), collabPrice = v(fd_collab_price);
    if (collab !== '')      fd.append('collaboration', collab);
    if (collabPrice !== '') fd.append('collaboration_price', collabPrice);

    const pics = v(fd_offer_pictures);
    if (pics !== '') pics.split(',').map(s => s.trim()).filter(Boolean)
        .forEach(p => fd.append('offer_pictures[]', p));

    const addons = v(fd_add_ons);
    if (addons !== '') addons.split(',').map(s => s.trim()).filter(Boolean)
        .forEach(a => fd.append('add_ons[]', a));

    // kirim file-file
    files.forEach(file => fd.append('offer_images[]', file));

    showProgress(0);
    await doFetch({
        method: 'POST', // <— BUKAN PATCH
        url: `${base}/api/offerings/${encodeURIComponent(id)}`,
        headers: {
        'Authorization': `Bearer ${token.value.trim()}`,
        'Accept': 'application/json'
        // jangan set Content-Type; biar browser set boundary
        },
        body: fd,
        onProgress: (pct) => showProgress(pct)
    });
    };


    // init
    function loadSessionToken(){ const t=sessionStorage.getItem('tester_token'); if(t){ token.value=t; setStatus('Token dari sessionStorage'); } }
    loadSessionToken();
  </script>
</body>
</html>

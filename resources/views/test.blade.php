<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <title>Offering Pictures Deleter</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    :root{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif}
    body{margin:24px; background:#0b0b0b; color:#eaeaea}
    h1{margin:0 0 16px}
    .card{border:1px solid #222; border-radius:12px; padding:16px; margin:12px 0; background:#111}
    label{display:block; font-size:14px; margin:8px 0 6px}
    input[type="text"],input[type="email"],input[type="password"]{width:100%; padding:10px; border-radius:8px; border:1px solid #333; background:#0e0e0e; color:#eaeaea}
    .row{display:grid; grid-template-columns:1fr 1fr; gap:12px}
    .btn{display:inline-block; padding:9px 12px; border:1px solid #444; border-radius:10px; background:#1a1a1a; color:#fff; cursor:pointer}
    .btn:hover{background:#222}
    .muted{opacity:.8; font-size:12px}
    pre{background:#0e0e0e; border:1px solid #222; padding:12px; border-radius:8px; overflow:auto; max-height:45vh}
    .pill{font-size:12px; padding:2px 8px; border:1px solid #333; border-radius:999px; background:#151515}
    .grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px}
    .thumb{border:1px solid #2a2a2a; border-radius:12px; overflow:hidden; background:#0b0b0b}
    .thumb img{display:block; width:100%; height:140px; object-fit:cover; background:#0f0f0f}
    .thumb .meta{padding:8px; font-size:12px; border-top:1px solid #222}
    .thumb .actions{display:flex; gap:6px; padding:8px; border-top:1px solid #222}
    .badge{font-size:12px; padding:2px 6px; border:1px solid #333; border-radius:6px}
    .flex{display:flex; gap:8px; align-items:center; flex-wrap:wrap}
    .right{margin-left:auto}
    .danger{background:#7a1f1f}
    .danger:hover{background:#8a2525}
    .ok{background:#1f5f2b}
    .ok:hover{background:#236b31}
  </style>
</head>
<body>
  <h1>Offering Pictures Deleter <span class="pill">DELETE /api/offerings/{id}/pictures</span></h1>

  <!-- Base & Offering -->
  <div class="card">
    <div class="row">
      <div>
        <label>Base URL</label>
        <input id="baseUrl" type="text" value="http://127.0.0.1:8000" />
      </div>
      <div>
        <label>Offering ID</label>
        <input id="offeringId" type="text" placeholder="contoh: 13 / UUID" />
      </div>
    </div>
  </div>

  <!-- Login -->
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
          <button id="togglePwd" class="btn" type="button">👁</button>
        </div>
      </div>
    </div>
    <div class="flex" style="margin-top:10px">
      <button id="btnLogin" class="btn" type="button">Login</button>
      <button id="btnLogout" class="btn" type="button">Logout</button>
      <label class="flex" style="gap:6px; margin-left:auto">
        <input id="rememberSess" type="checkbox" />
        <span class="muted">Simpan token di sessionStorage</span>
      </label>
    </div>
    <label style="margin-top:12px">Bearer Token</label>
    <input id="token" type="text" placeholder="Terisi otomatis setelah login" />
  </div>

  <!-- Controls -->
  <div class="card">
    <div class="flex">
      <h3 style="margin:0">Gambar Offering</h3>
      <button id="btnLoad" class="btn right" type="button">Muat / Refresh</button>
    </div>
    <div class="flex" style="margin-top:8px">
      <label class="flex" style="gap:6px">
        <input id="alsoDelete" type="checkbox" />
        <span>Juga hapus file fisik di storage</span>
      </label>
    </div>
    <div id="gallery" class="grid" style="margin-top:12px"></div>
  </div>

  <!-- Manual delete -->
  <div class="card">
    <h3 style="margin-top:0">Hapus Manual</h3>
    <div class="row">
      <div>
        <label>Hapus by Index</label>
        <div class="flex">
          <input id="delIndex" type="text" placeholder="contoh: 0" />
          <button id="btnDelIndex" class="btn danger" type="button">Hapus Index</button>
        </div>
      </div>
      <div>
        <label>Hapus by URL</label>
        <div class="flex">
          <input id="delUrl" type="text" placeholder="/storage/offering/a.jpg" />
          <button id="btnDelUrl" class="btn danger" type="button">Hapus URL</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Debug -->
  <div class="card">
    <label>Request Preview</label>
    <pre id="reqPreview">–</pre>
    <label>Response</label>
    <pre id="resBox">–</pre>
  </div>

  <script>
    const $ = (id) => document.getElementById(id);

    // elements
    const baseUrl   = $('baseUrl');
    const offeringId= $('offeringId');
    const token     = $('token');

    const loginEmail= $('loginEmail');
    const loginPassword = $('loginPassword');
    const btnLogin  = $('btnLogin');
    const btnLogout = $('btnLogout');
    const togglePwd = $('togglePwd');
    const rememberSess = $('rememberSess');
    const loginStatus = $('loginStatus');

    const btnLoad   = $('btnLoad');
    const gallery   = $('gallery');
    const alsoDelete= $('alsoDelete');

    const delIndex  = $('delIndex');
    const btnDelIndex = $('btnDelIndex');
    const delUrl    = $('delUrl');
    const btnDelUrl = $('btnDelUrl');

    const reqPreview= $('reqPreview');
    const resBox    = $('resBox');

    // helpers
    const getBase = () => baseUrl.value.replace(/\/+$/, '');
    const setStatus = (s) => loginStatus.textContent = s;

    function showRequestPreview({ method, url, headers, body }) {
      const safe = { ...(headers||{}) };
      if (safe.Authorization) safe.Authorization = 'Bearer ***masked***';
      let text = `${method} ${url}\n\nHeaders:\n${JSON.stringify(safe, null, 2)}`;
      if (body instanceof FormData) {
        const o = {}; body.forEach((v,k)=>{o[k]=v instanceof File?`(file ${v.name})`:v}); 
        text += `\n\nFormData:\n${JSON.stringify(o, null, 2)}`;
      } else if (typeof body === 'string') {
        text += `\n\nBody:\n${body}`;
      }
      reqPreview.textContent = text;
    }

    async function doFetch({ method, url, headers, body }) {
      showRequestPreview({ method, url, headers, body });
      try {
        const res = await fetch(url, { method, headers, body });
        const ct = res.headers.get('content-type') || '';
        let data;
        if (ct.includes('application/json')) {
          data = await res.json();
          resBox.textContent = `HTTP ${res.status}\n\n` + JSON.stringify(data, null, 2);
        } else {
          const t = await res.text();
          resBox.textContent = `HTTP ${res.status}\n\n` + t;
        }
        return { ok: res.ok, status: res.status, data };
      } catch (e) {
        resBox.textContent = 'Request error: ' + e.message;
        return { ok:false, error:e };
      }
    }

    function absoluteImageSrc(path){
      // if path already absolute (http...), return as is
      if (/^https?:\/\//i.test(path)) return path;
      // usually backend returns "/storage/.."
      return getBase().replace(/\/+$/,'') + path;
    }

    function renderGallery(offering){
      gallery.innerHTML = '';
      const pics = Array.isArray(offering.offer_pictures) ? offering.offer_pictures : [];
      if (!pics.length) {
        gallery.innerHTML = '<div class="muted">Belum ada gambar.</div>';
        return;
      }
      pics.forEach((p, idx) => {
        const wrap = document.createElement('div'); wrap.className = 'thumb';
        const img  = document.createElement('img'); img.src = absoluteImageSrc(p); img.alt = p;
        const meta = document.createElement('div'); meta.className='meta';
        meta.innerHTML = `<div><b>#${idx}</b></div><div style="word-break:break-all">${p}</div>`;
        const actions = document.createElement('div'); actions.className='actions';
        const b1 = document.createElement('button'); b1.className='btn danger'; b1.textContent='Hapus (Index)'; 
        b1.onclick = () => deleteByIndex(idx, alsoDelete.checked);
        const b2 = document.createElement('button'); b2.className='btn danger'; b2.textContent='Hapus (URL)';
        b2.onclick = () => deleteByUrl(p, alsoDelete.checked);
        actions.appendChild(b1); actions.appendChild(b2);
        wrap.appendChild(img); wrap.appendChild(meta); wrap.appendChild(actions);
        gallery.appendChild(wrap);
      });
    }

    // API calls
    async function login(){
      const email = loginEmail.value.trim();
      const pwd   = loginPassword.value;
      if(!email || !pwd) { alert('Isi email & password'); return; }
      const res = await doFetch({
        method:'POST',
        url: `${getBase()}/api/auth/login`,
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({ email, password: pwd })
      });
      if(res.ok && res.data && res.data.token){
        token.value = res.data.token;
        setStatus('Login OK');
        if (rememberSess.checked) sessionStorage.setItem('tester_token', res.data.token);
        else sessionStorage.removeItem('tester_token');
      } else {
        setStatus(`Login gagal (${res.status || 'ERR'})`);
      }
    }

    async function loadOffering(){
      const id = offeringId.value.trim();
      if(!id) { alert('Isi Offering ID'); return; }
      const res = await doFetch({
        method:'GET',
        url: `${getBase()}/api/offerings/${encodeURIComponent(id)}`,
        headers: { 'Accept':'application/json' }
      });
      if(res.ok && res.data) renderGallery(res.data);
    }

    // Delete helpers — use POST + _method=DELETE (compat with Laravel)
    async function deleteByIndex(index, alsoDel){
      const id = offeringId.value.trim();
      if(!id) return alert('Isi Offering ID');
      if(!token.value.trim()) return alert('Login dulu');

      const fd = new FormData();
      fd.append('_method', 'DELETE');
      fd.append('index', String(index));
      fd.append('also_delete_files', alsoDel ? '1' : '0');

      const res = await doFetch({
        method:'POST',
        url: `${getBase()}/api/offerings/${encodeURIComponent(id)}/pictures`,
        headers: { 'Authorization': `Bearer ${token.value.trim()}`, 'Accept':'application/json' },
        body: fd
      });
      if(res.ok && res.data) renderGallery(res.data);
    }

    async function deleteByUrl(url, alsoDel){
      const id = offeringId.value.trim();
      if(!id) return alert('Isi Offering ID');
      if(!token.value.trim()) return alert('Login dulu');

      const fd = new FormData();
      fd.append('_method', 'DELETE');
      fd.append('also_delete_files', alsoDel ? '1' : '0');
      fd.append('pictures[]', url);

      const res = await doFetch({
        method:'POST',
        url: `${getBase()}/api/offerings/${encodeURIComponent(id)}/pictures`,
        headers: { 'Authorization': `Bearer ${token.value.trim()}`, 'Accept':'application/json' },
        body: fd
      });
      if(res.ok && res.data) renderGallery(res.data);
    }

    // UI events
    btnLogin.onclick = login;
    btnLogout.onclick = () => { token.value=''; sessionStorage.removeItem('tester_token'); setStatus('Belum login'); };
    togglePwd.onclick = () => { loginPassword.type = loginPassword.type === 'password' ? 'text' : 'password'; };
    btnLoad.onclick = loadOffering;

    btnDelIndex.onclick = () => {
      const idx = parseInt(delIndex.value, 10);
      if (Number.isNaN(idx)) return alert('Index harus angka');
      deleteByIndex(idx, alsoDelete.checked);
    };
    btnDelUrl.onclick = () => {
      const url = delUrl.value.trim();
      if(!url) return alert('Isi URL gambar');
      deleteByUrl(url, alsoDelete.checked);
    };

    // init
    (function init(){
      const t = sessionStorage.getItem('tester_token');
      if (t) { token.value = t; setStatus('Token dari sessionStorage'); }
    })();
  </script>
</body>
</html>

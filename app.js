const API_URL = "api.php";

const baseNeeds = [
  ["Hidratación","Agua embotellada","Urgente","Botellas"],["Protección / EPI","Mascarillas","Urgente","Unidades"],["Protección / EPI","Gafas de protección","Urgente","Unidades"],["Protección / EPI","Guantes","Urgente","Pares"],["Protección / EPI","Chaqueta de seguridad ignífuga","Si disponible","Unidades"],["Alimentación","Garbanzos cocidos","Necesario","Kilos"],["Alimentación","Judías verdes cocidas","Necesario","Kilos"],["Alimentación","Hummus","Necesario","Unidades"],["Alimentación","Tortillas","Necesario","Unidades"],["Alimentación","Atún","Necesario","Latas"],["Alimentación","Pan (varios tipos)","Necesario","Barras / bolsas"],["Herramientas / mantenimiento","Aceite de motosierra","Necesario","Litros"],["Herramientas / mantenimiento","Aceite mezcla 2T / desbrozadora","Necesario","Litros"],["Herramientas / mantenimiento","Hilo de desbrozadora","Opcional","Unidades"],["Herramientas / apoyo","Pulverizadora de mochila","Si disponible","Unidades"],["Petición voluntariado","Tabaco (solo adultos)","Opcional","Unidades"],["Petición voluntariado","Cerveza (solo adultos)","Opcional","Unidades"]
];

const options = {
  location:["Alcorcón","Móstoles","Fuenlabrada","Leganés","Arroyomolinos","Villaviciosa de Odón","Boadilla del Monte","Navalcarnero","Parla","El Álamo","Griñón","Moraleja de Enmedio","Humanes de Madrid","Batres","Serranillos del Valle","Cubas de la Sagra","Casarrubuelos","Torrejón de la Calzada","Torrejón de Velasco","Getafe","Madrid","Yuncler","Illescas","Aranjuez","Valdemoro","Otra población"],
  participationType:["Aportar material / comida","Preparar comida","Transportar","Recoger compras / donaciones","Clasificar / almacenar","Repartir","Coordinar"],
  unit:["Botellas","Packs","Unidades","Pares","Litros","Kilos","Latas","Barras / bolsas","Comidas","Cajas","Otro"],
  availability:["Ahora / cuanto antes","Mañanas","Tardes","Todo el día","Fin de semana","Flexible","A confirmar"],
  vehicle:["No","Coche","Coche grande / SUV","Furgoneta","Moto","Otro"],
  canTransport:["Sí","No"]
};

const helpMeta = {
  "Aportar material / comida":{icon:"▣",items:baseNeeds.map(x=>x[1])},
  "Preparar comida":{icon:"◒",items:["Preparación de comida"]},
  "Transportar":{icon:"→",items:["Transporte de material"]},
  "Recoger compras / donaciones":{icon:"↙",items:["Recogida de donaciones / compras"]},
  "Clasificar / almacenar":{icon:"≡",items:["Clasificación / inventario"]},
  "Repartir":{icon:"↗",items:["Reparto"]},
  "Coordinar":{icon:"◎",items:["Coordinación"]}
};

let adminUnlocked = false;

function esc(s="") {
  return String(s).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[m]));
}

async function api(action, options = {}) {
  const response = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
    credentials: "same-origin",
    cache: "no-store",
    ...options,
    headers: {
      ...(options.body ? {"Content-Type":"application/json"} : {}),
      ...(options.headers || {})
    }
  });
  const data = await response.json().catch(()=>({ok:false,error:"Respuesta no válida del servidor"}));
  if (!response.ok || !data.ok) {
    const err = new Error(data.error || "Error de servidor");
    err.status = response.status;
    throw err;
  }
  return data;
}

function fillSelect(id, values) {
  const el = document.getElementById(id);
  if (!el) return;
  values.forEach(v=>{
    const o = document.createElement("option");
    o.value = v;
    o.textContent = v;
    el.appendChild(o);
  });
}
["location","unit","availability","vehicle","canTransport"].forEach(id=>fillSelect(id, options[id]));

function renderHelpChoices() {
  const wrap = document.getElementById("helpChoices");
  wrap.innerHTML = options.participationType.map(type=>`<button type="button" class="help-choice" data-help-type="${esc(type)}"><span class="choice-icon">${helpMeta[type].icon}</span><strong>${esc(type)}</strong></button>`).join("");
  wrap.querySelectorAll("[data-help-type]").forEach(btn=>btn.addEventListener("click",()=>selectHelpType(btn.dataset.helpType)));
}

function selectHelpType(type) {
  document.getElementById("participationType").value = type;
  document.getElementById("participationError").textContent = "";
  document.querySelectorAll("[data-help-type]").forEach(b=>b.classList.toggle("active",b.dataset.helpType===type));
  const select = document.getElementById("needOrTask");
  const items = helpMeta[type]?.items || [];
  select.innerHTML = `<option value="">${type==="Aportar material / comida"?"Selecciona qué aportas":"Selecciona la tarea"}</option>` + items.map(x=>`<option value="${esc(x)}">${esc(x)}</option>`).join("");
  if (items.length===1) select.value = items[0];
  if (["Transportar","Recoger compras / donaciones","Repartir"].includes(type)) document.getElementById("moreDetails").open = true;
  setTimeout(()=>document.getElementById("taskSection").scrollIntoView({behavior:"smooth",block:"center"}),80);
}
renderHelpChoices();

function setAdmin(on) {
  adminUnlocked = !!on;
  document.getElementById("adminBtn").textContent = on ? "Salir de administración" : "Administración";
  document.querySelectorAll(".tab.locked").forEach(t=>t.classList.toggle("unlocked",on));
}

async function checkAdminSession() {
  try {
    const result = await api("admin-session");
    setAdmin(result.admin);
  } catch {
    setAdmin(false);
  }
}

function showAdminDialog() {
  document.getElementById("adminError").textContent = "";
  document.getElementById("adminDialog").showModal();
  setTimeout(()=>document.getElementById("adminPassword").focus(),50);
}

document.querySelectorAll(".tab").forEach(btn=>btn.addEventListener("click",()=>{
  const view = btn.dataset.view;
  if (view!=="apuntate" && !adminUnlocked) {
    showAdminDialog();
    return;
  }
  openView(view);
}));

async function openView(view) {
  document.querySelectorAll(".tab").forEach(t=>t.classList.toggle("active",t.dataset.view===view));
  document.querySelectorAll(".view").forEach(v=>v.classList.remove("active"));
  document.getElementById("view-"+view).classList.add("active");
  if (view==="necesidades") await renderNeeds();
  if (view==="tareas") await renderTasks();
  if (view==="resumen") await renderSummary();
  window.scrollTo({top:0,behavior:"smooth"});
}

document.getElementById("adminBtn").addEventListener("click",async()=>{
  if (adminUnlocked) {
    try { await api("admin-logout",{method:"POST",body:"{}"}); } catch {}
    setAdmin(false);
    openView("apuntate");
  } else {
    showAdminDialog();
  }
});

document.getElementById("unlockBtn").addEventListener("click",async e=>{
  e.preventDefault();
  const btn = e.currentTarget;
  const password = document.getElementById("adminPassword").value;
  if (!password) return;
  btn.disabled = true;
  document.getElementById("adminError").textContent = "Comprobando…";
  try {
    await api("admin-login",{method:"POST",body:JSON.stringify({password})});
    setAdmin(true);
    document.getElementById("adminPassword").value = "";
    document.getElementById("adminError").textContent = "";
    document.getElementById("adminDialog").close();
  } catch (err) {
    document.getElementById("adminError").textContent = err.message;
  } finally {
    btn.disabled = false;
  }
});

document.getElementById("signupForm").addEventListener("submit", async e=>{
  e.preventDefault();
  const form = e.currentTarget;
  const participationType = document.getElementById("participationType").value;
  const saveMsg = document.getElementById("saveMsg");
  const submit = form.querySelector('button[type="submit"]');

  if (!participationType) {
    document.getElementById("participationError").textContent = "Selecciona cómo puedes ayudar.";
    document.getElementById("helpChoices").scrollIntoView({behavior:"smooth",block:"center"});
    return;
  }
  if (!form.checkValidity()) {
    form.reportValidity();
    return;
  }

  const data = {
    website: document.getElementById("website")?.value || "",
    name: document.getElementById("name").value.trim(),
    phone: document.getElementById("phone").value.trim(),
    location: document.getElementById("location").value,
    participationType,
    needOrTask: document.getElementById("needOrTask").value,
    quantity: Number(document.getElementById("quantity").value || 0),
    unit: document.getElementById("unit").value,
    availability: document.getElementById("availability").value,
    vehicle: document.getElementById("vehicle").value,
    canTransport: document.getElementById("canTransport").value,
    destination: document.getElementById("destination").value.trim(),
    notes: document.getElementById("notes").value.trim()
  };

  submit.disabled = true;
  submit.textContent = "Guardando…";
  saveMsg.classList.remove("error");
  saveMsg.textContent = "";

  try {
    const result = await api("signup",{method:"POST",body:JSON.stringify(data)});
    form.reset();
    document.getElementById("participationType").value = "";
    document.querySelectorAll("[data-help-type]").forEach(b=>b.classList.remove("active"));
    document.getElementById("needOrTask").innerHTML = '<option value="">Selecciona primero cómo puedes ayudar</option>';
    document.getElementById("moreDetails").open = false;
    saveMsg.textContent = "Registrado correctamente";
    if (result.stats) updatePublicKpis(result.stats);
    document.querySelector(".hero-panel").scrollIntoView({behavior:"smooth",block:"start"});
    setTimeout(()=>saveMsg.textContent="",4000);
  } catch (err) {
    saveMsg.classList.add("error");
    saveMsg.textContent = `No se ha guardado: ${err.message}`;
  } finally {
    submit.disabled = false;
    submit.textContent = "Apuntarme para ayudar";
  }
});

function updatePublicKpis(stats) {
  document.getElementById("kpiPeople").textContent = Number(stats.active ?? stats.people ?? 0);
  document.getElementById("kpiTransport").textContent = Number(stats.transport ?? 0);
}

async function renderPublicKpis() {
  try {
    const r = await api("public-stats");
    updatePublicKpis(r.stats);
    document.getElementById("backendStatus").textContent = "Registro central activo";
    document.getElementById("backendStatus").classList.add("online");
  } catch {
    document.getElementById("backendStatus").textContent = "Registro temporalmente no disponible";
    document.getElementById("backendStatus").classList.add("offline");
  }
}

function priorityClass(priority) {
  if (priority==="Urgente") return "urgent";
  if (priority==="Necesario") return "needed";
  return "";
}

async function renderNeeds() {
  const el = document.getElementById("needsList");
  el.innerHTML = '<div class="card"><p>Cargando necesidades…</p></div>';
  try {
    const result = await api("needs");
    el.innerHTML = result.needs.map(n=>{
      const c = Number(n.committed || 0);
      const d = Number(n.delivered || 0);
      const hasTarget = n.target !== null && n.target !== "";
      const r = hasTarget ? Math.max(0, Number(n.target)-c) : "—";
      const pc = priorityClass(n.priority);
      return `<div class="need-row ${pc?`is-${pc}`:""}"><div class="need-title"><strong>${esc(n.need)}</strong>${pc?`<span class="priority-chip ${pc}">${esc(n.priority)}</span>`:""}<div class="meta">${esc(n.category)} · ${esc(n.unit)}</div></div><label>Objetivo<input data-need-id="${n.id}" type="number" min="0" step="0.01" value="${n.target ?? ""}" placeholder="0"></label><div class="metric-cell metric-committed"><strong>${c}</strong><span>Comprometido</span></div><div class="metric-cell metric-delivered"><strong>${d}</strong><span>Entregado</span></div><div class="metric-cell metric-pending"><strong>${r}</strong><span>Pendiente</span></div></div>`;
    }).join("");
    document.querySelectorAll("[data-need-id]").forEach(inp=>inp.addEventListener("change",async()=>{
      const oldDisabled = inp.disabled;
      inp.disabled = true;
      try {
        await api("need-update",{method:"POST",body:JSON.stringify({id:Number(inp.dataset.needId),target:inp.value})});
        await renderNeeds();
      } catch (err) {
        alert(`No se pudo guardar: ${err.message}`);
      } finally {
        inp.disabled = oldDisabled;
      }
    }));
  } catch (err) {
    if (err.status===401) setAdmin(false);
    el.innerHTML = `<div class="card"><p>No se pudieron cargar las necesidades: ${esc(err.message)}</p></div>`;
  }
}

async function renderTasks() {
  const el = document.getElementById("tasksList");
  el.innerHTML = '<div class="card"><p>Cargando tareas…</p></div>';
  try {
    const result = await api("tasks");
    el.innerHTML = result.tasks.map(t=>`<article class="task-card status-${esc(t.status).toLowerCase().replace(/\s+/g,"-")}"><div class="task-card-top"><div><h3>${esc(t.task)}</h3><p>${esc(t.description)}</p></div><div class="task-count"><strong>${Number(t.signed||0)}</strong><span>apuntadas</span></div></div><div class="task-fields"><label>Zona / punto<input data-task-field="zone" data-task-id="${t.id}" value="${esc(t.zone||"")}" placeholder="Sin asignar"></label><label>Plazas<input data-task-field="requiredPeople" data-task-id="${t.id}" type="number" min="0" value="${t.required_people ?? ""}" placeholder="0"></label><label class="task-status">Estado<select data-task-field="status" data-task-id="${t.id}">${["Pendiente","Asignada","En curso","Completada","Cancelada"].map(x=>`<option ${x===t.status?"selected":""}>${x}</option>`).join("")}</select></label></div></article>`).join("");
    document.querySelectorAll("[data-task-field]").forEach(elm=>elm.addEventListener("change",async()=>{
      const card = elm.closest(".task-card");
      const id = Number(elm.dataset.taskId);
      const zone = card.querySelector('[data-task-field="zone"]').value;
      const requiredPeople = card.querySelector('[data-task-field="requiredPeople"]').value;
      const status = card.querySelector('[data-task-field="status"]').value;
      try {
        await api("task-update",{method:"POST",body:JSON.stringify({id,zone,requiredPeople,status})});
        if (elm.dataset.taskField==="status") await renderTasks();
      } catch (err) {
        alert(`No se pudo guardar: ${err.message}`);
      }
    }));
  } catch (err) {
    if (err.status===401) setAdmin(false);
    el.innerHTML = `<div class="card"><p>No se pudieron cargar las tareas: ${esc(err.message)}</p></div>`;
  }
}

async function renderSummary() {
  const el = document.getElementById("summaryCards");
  el.innerHTML = '<div class="summary-card"><span>Cargando</span><strong>…</strong></div>';
  try {
    const result = await api("summary");
    const s = result.summary;
    el.innerHTML = [["Personas apuntadas",s.total],["Compromisos activos",s.active],["Entregas completadas",s.delivered],["Tareas en curso",s.inProgress],["Con transporte",s.transport]].map(([l,v])=>`<div class="summary-card"><span>${esc(l)}</span><strong>${Number(v||0)}</strong></div>`).join("");
  } catch (err) {
    if (err.status===401) setAdmin(false);
    el.innerHTML = `<div class="summary-card"><span>Error</span><strong>—</strong><small>${esc(err.message)}</small></div>`;
  }
}

document.getElementById("exportBtn").addEventListener("click",()=>{
  window.location.href = `${API_URL}?action=export-csv`;
});

checkAdminSession();
renderPublicKpis();

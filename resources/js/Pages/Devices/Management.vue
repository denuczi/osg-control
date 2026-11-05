<script setup lang="ts">
import { formatClock } from '@/Lib/displayTimezone';
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Clipboard, Plus, RefreshCw, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { urls, type Paginated } from '@/types/nodo';

type Pending = {uuid:string;hostname:string;platform:string;source_ip:string|null;expires_at:string;failed_attempts:number};
type DeviceItem = {uuid:string;name:string;hostname:string;platform?:string;archived?:boolean;revoked?:boolean};
const props=defineProps<{
  pairingRequests: Pending[];
  activeDevices: Paginated<DeviceItem>;
  inactiveDevices: Paginated<DeviceItem>;
  legacyCode: string|null;
}>();

const codes=reactive<Record<string,string>>({});
const errors=ref<Record<string,string>>({});
const busy=ref(false);
const legacyBusy=ref(false);
const dialog=ref<{kind:'revoke'|'archive'|'delete'|'restore';device:DeviceItem}|null>(null);
const confirmation=ref('');
const copyOk=ref(false);
const confirmForm=useForm({confirm:true,confirm_name:''});
// Error del ciclo de vida: no es un campo del formulario de confirmación.
const lifecycleError=ref<string|null>(null);

function approve(requestId:string){
  const code=(codes[requestId]??'').trim();
  if(!/^\d{6}$/.test(code)){errors.value[requestId]='Introduce los seis dígitos que aparecen en Windows.';return;}
  busy.value=true;
  router.post(`/equipos/vinculaciones/${encodeURIComponent(requestId)}/aprobar`, {verification_code:code},{
    preserveScroll:true,
    onError: e=>{errors.value[requestId]=e.verification_code??'No se pudo autorizar la solicitud.';},
    onSuccess:()=>{codes[requestId]='';delete errors.value[requestId];},
    onFinish:()=>{busy.value=false;},
  });
}
function reject(requestId:string){
  if(!window.confirm('¿Rechazar esta solicitud de vinculación?'))return;
  router.post(`/equipos/vinculaciones/${encodeURIComponent(requestId)}/rechazar`,{},{preserveScroll:true});
}
function generateCode(){
  legacyBusy.value=true;
  router.post('/equipos/codigos',{}, {preserveScroll:true,onFinish:()=>{legacyBusy.value=false;}});
}
async function copyLegacy(){
  if(!props.legacyCode)return;
  try{await navigator.clipboard.writeText(props.legacyCode);copyOk.value=true;}
  catch{copyOk.value=false;}
}
function openAction(kind:'revoke'|'archive'|'delete'|'restore',device:DeviceItem){
  dialog.value={kind,device};confirmation.value='';confirmForm.reset();confirmForm.clearErrors();lifecycleError.value=null;
}
function executeAction(){
  if(!dialog.value)return;
  const {kind,device}=dialog.value;
  const route=`/equipos/${encodeURIComponent(device.uuid)}`;
  if(kind==='delete'){
    confirmForm.confirm_name=confirmation.value;
    confirmForm.delete(route,{
      preserveScroll:true,
      onError:(validationErrors)=>{lifecycleError.value=validationErrors.device??null;},
      onSuccess:()=>{dialog.value=null;},
    });
    return;
  }
  const suffix={revoke:'revocar',archive:'archivar',restore:'restaurar'}[kind];
  confirmForm.post(`${route}/${suffix}`,{preserveScroll:true,onSuccess:()=>{dialog.value=null;}});
}
</script>

<template>
  <AppLayout>
    <Head title="Administrar equipos" />
    <div class="detail-back"><Link :href="urls.devices"><ArrowLeft :size="15"/> Volver a equipos</Link></div>
    <div class="page-titlebar"><div><h1>Vinculación y administración</h1><p>Autorización de nuevas computadoras, acceso y baja de equipos.</p></div></div>

    <section class="surface-panel management-panel">
      <div class="section-head"><div><h2><ShieldCheck :size="18"/> Solicitudes pendientes</h2><p>Verifica el código que aparece en el agente de Windows antes de autorizar una computadora.</p></div>
        <button type="button" class="button button-secondary" @click="router.reload({only:['pairingRequests']})"><RefreshCw :size="15"/> Actualizar</button></div>
      <ol class="pairing-steps" aria-label="Cómo vincular una computadora">
        <li><span>En Windows, abre <strong>OSG Nodo Agent</strong> y selecciona <strong>Solicitar vinculación</strong>.</span></li>
        <li><span>Comprueba el nombre del equipo y la dirección IP en la solicitud pendiente.</span></li>
        <li><span>Introduce el código de seis dígitos que aparece en Windows y selecciona <strong>Autorizar</strong>.</span></li>
      </ol>
      <p class="pairing-expiration">Por seguridad, las solicitudes caducan después de cinco minutos.</p>
      <div v-if="!pairingRequests.length" class="management-empty">No hay solicitudes pendientes de autorización.</div>
      <div v-else class="management-request-list">
        <div v-for="item in pairingRequests" :key="item.uuid" class="management-request">
          <div class="management-request-info"><strong>{{ item.hostname }}</strong><span>{{ item.platform }} · IP de origen: {{ item.source_ip??'No disponible' }}</span><span>Vence: {{ formatClock(item.expires_at) }} · {{ item.failed_attempts }} intentos incorrectos</span></div>
          <form class="management-approval-form" @submit.prevent="approve(item.uuid)">
            <label :for="`pair-${item.uuid}`">Código mostrado en Windows</label>
            <div class="management-approval-controls"><input :id="`pair-${item.uuid}`" v-model="codes[item.uuid]" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" placeholder="000000" required/>
              <button class="button button-primary" type="submit" :disabled="busy">Autorizar</button>
              <button class="button button-secondary" type="button" :disabled="busy" @click="reject(item.uuid)">Rechazar</button></div>
            <p v-if="errors[item.uuid]" class="field-error" role="alert">{{ errors[item.uuid] }}</p>
          </form>
        </div>
      </div>
    </section>

    <section class="surface-panel management-panel">
      <div class="section-head"><div><h2>Vinculación clásica</h2><p>Opción de respaldo para instalaciones con el asistente anterior.</p></div></div>
      <div class="management-legacy">
        <p>Genera un código de registro válido durante 15 minutos. Se muestra una sola vez y debes introducirlo en Windows. No lo compartas con personas ajenas a la red administrada.</p>
        <button class="button button-secondary" type="button" :disabled="legacyBusy" @click="generateCode"><Plus :size="16"/> {{legacyBusy?'Generando…':'Generar código de registro'}}</button>
        <div v-if="legacyCode" class="management-secret" aria-live="polite"><span>Nuevo código (una sola visualización)</span><code>{{legacyCode}}</code><button class="button button-secondary" type="button" @click="copyLegacy"><Check v-if="copyOk" :size="15"/><Clipboard v-else :size="15"/> {{copyOk?'Copiado':'Copiar'}}</button></div>
      </div>
    </section>

    <section class="surface-panel management-panel">
      <div class="section-head"><div><h2>Equipos activos</h2><p>Revocar acceso o archivar un equipo sin borrar su historial.</p></div></div>
      <div v-if="!activeDevices.data.length" class="management-empty">No hay equipos activos.</div>
      <div v-else class="table-scroll"><table class="data-table management-table"><thead><tr><th>Equipo</th><th>Sistema</th><th>Administración</th></tr></thead><tbody><tr v-for="device in activeDevices.data" :key="device.uuid"><td><Link :href="urls.device(device.uuid)" class="table-link">{{device.name}}</Link><div class="cell-secondary">{{device.hostname}}</div></td><td>{{device.platform}}</td><td><div class="management-actions"><button class="button button-secondary" type="button" @click="openAction('revoke',device)">Desvincular</button><button class="button button-secondary" type="button" @click="openAction('archive',device)">Archivar</button><button class="button button-text-danger" type="button" @click="openAction('delete',device)">Eliminar</button></div></td></tr></tbody></table></div>
      <Pagination :pagination="activeDevices" />
    </section>

    <section class="surface-panel management-panel">
      <div class="section-head"><div><h2>Equipos archivados o desvinculados</h2><p>El historial se mantiene hasta que se elimine definitivamente.</p></div></div>
      <div v-if="!inactiveDevices.data.length" class="management-empty">No hay equipos archivados ni desvinculados.</div>
      <div v-else class="table-scroll"><table class="data-table management-table"><thead><tr><th>Equipo</th><th>Estado</th><th>Administración</th></tr></thead><tbody><tr v-for="device in inactiveDevices.data" :key="device.uuid"><td><strong>{{device.name}}</strong><div class="cell-secondary">{{device.hostname}}</div></td><td>{{device.archived?'Archivado':'Desvinculado'}}</td><td><div class="management-actions"><button v-if="device.archived" class="button button-secondary" type="button" @click="openAction('restore',device)">Restaurar inventario</button><button v-else class="button button-secondary" type="button" @click="openAction('archive',device)">Archivar</button><button class="button button-text-danger" type="button" @click="openAction('delete',device)">Eliminar</button></div></td></tr></tbody></table></div>
      <Pagination :pagination="inactiveDevices" />
    </section>

    <div v-if="dialog" class="modal-overlay" @click.self="dialog=null"><form class="dialog" role="dialog" aria-modal="true" aria-labelledby="management-dialog-title" @submit.prevent="executeAction">
      <div class="dialog-header"><h2 id="management-dialog-title">{{dialog.kind==='delete'?'Eliminar definitivamente':dialog.kind==='revoke'?'Desvincular equipo':dialog.kind==='archive'?'Archivar equipo':'Restaurar inventario'}}</h2></div>
      <div class="dialog-body"><p><strong>{{dialog.device.name}}</strong></p>
        <p v-if="dialog.kind==='delete'">Esta acción elimina inventario, métricas, eventos e informes asociados al equipo. <strong>No puede deshacerse.</strong> No desinstala el agente Windows.</p>
        <p v-else-if="dialog.kind==='archive'">El equipo dejará de recibir órdenes y métricas. Sus credenciales se revocarán y el historial se conservará.</p>
        <p v-else-if="dialog.kind==='revoke'">El agente perderá acceso al servidor. Los datos históricos permanecerán guardados.</p>
        <p v-else>El equipo volverá a la lista de inventario, pero su acceso seguirá revocado. Requiere una vinculación autorizada nueva.</p>
        <label v-if="dialog.kind==='delete'" class="form-field"><span>Para confirmar, escribí exactamente «{{dialog.device.name}}»</span><input v-model="confirmation" autocomplete="off" required/></label>
        <p v-if="confirmForm.errors.confirm_name||lifecycleError" class="field-error" role="alert">{{confirmForm.errors.confirm_name||lifecycleError}}</p>
      </div><div class="dialog-actions"><button type="button" class="button button-secondary" @click="dialog=null">Cancelar</button><button type="submit" class="button" :class="dialog.kind==='delete'?'button-danger':'button-primary'" :disabled="confirmForm.processing || (dialog.kind==='delete' && confirmation!==dialog.device.name)">{{confirmForm.processing?'Procesando…':'Confirmar'}}</button></div>
    </form></div>
  </AppLayout>
</template>

@php
$panelSurvey=null; $panelUser=auth($surveyGuard)->user(); $panelSeen=null;
try {
 if($panelUser && !session('impersonation.readonly') && ($panelUser->company?->status !== 'pending')
 && \Illuminate\Support\Facades\Schema::hasTable('surveys') && \App\Models\SystemSetting::get('pos_surveys_enabled','1')==='1') {
  foreach(\App\Models\Survey::active()->latest()->get() as $candidate) {
   if($candidate->eligibleFor($panelUser) && !$candidate->answeredBy($panelUser)) {
    $panelSurvey=$candidate;
    $panelSeen=$candidate->responses()->where('user_id',$panelUser->id)->first(); break;
   }
  }
 }
} catch(\Throwable $ignored) {}
@endphp
@if($panelSurvey)
<details data-internal-survey="{{ $panelSurvey->id }}" style="z-index:70" class="fixed bottom-4 right-4 w-80 max-w-full rounded-xl border bg-white dark:bg-gray-900 p-4 shadow-xl" @if(!$panelSeen?->updated_at?->isToday()) open @endif>
 <summary class="cursor-pointer font-bold">{{ $panelSurvey->title }}</summary>
 <form method="POST" data-survey-form class="space-y-3 mt-3" action="{{ route($surveyRoute.'.survey.respond',$panelSurvey->id) }}">
  @csrf
 <p>{{ $panelSurvey->intro }}</p>
  @foreach($panelSurvey->questions as $q)
  <label class="block text-sm">{{ $q['text'] }}
   @if(($q['type'] ?? 'choice')==='text')<textarea required maxlength="2000" name="answers[{{ $q['key'] }}]" class="w-full rounded border p-2" rows="3"></textarea>
   @else<select required name="answers[{{ $q['key'] }}]" class="w-full rounded border p-2"><option value="">—</option>@foreach($q['options'] as $opt)<option value="{{ $opt['key'] }}">{{ $opt['label'] }}</option>@endforeach</select>@endif
  </label>
  @endforeach
  @if($panelSurvey->allow_comment)<label class="block">{{ __('pos.survey_comment_label') }}<textarea name="comment" maxlength="2000" class="w-full rounded border p-2"></textarea></label>@endif
  <p data-survey-status role="status"></p>
  <button class="rounded-lg bg-emerald-700 px-4 py-2 text-white" type="submit">{{ __('pos.survey_answer_btn') }}</button>
  <button type="button" data-survey-later data-url="{{ route($surveyRoute.'.survey.dismiss',$panelSurvey->id) }}" class="rounded-lg border px-4 py-2">{{ __('pos.survey_later_btn') }}</button>
 </form>
</details>
<script>
(() => {
 const expires=@json($panelSurvey->expires_at?->toIso8601String());
 const root=document.querySelector('[data-internal-survey]'), form=root.querySelector('form'), status=root.querySelector('[data-survey-status]');
 const send=(url,body)=>fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(body)});
 const expire=()=>{if(!expires)return;const remaining=Date.parse(expires)-Date.now();if(remaining<=0){root.remove();return;}setTimeout(expire,Math.min(remaining,86400000));};expire();
 let busy=false;
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(busy||!form.reportValidity())return;busy=true;
  const fields=new FormData(form),answers={};for(const [name,value] of fields)if(name.startsWith('answers['))answers[name.slice(8,-1)]=value;
  try{const r=await send(form.action,{answers,comment:fields.get('comment')});const j=await r.json();if(!r.ok||!j.ok)throw new Error();root.remove();}
  catch{status.textContent=@js(__('pos.network_error_dot'));}finally{busy=false;}
 });
 root.querySelector('[data-survey-later]').addEventListener('click',async e=>{
  if(busy)return;busy=true;try{const r=await send(e.currentTarget.dataset.url,{});if(!r.ok)throw new Error();root.open=false;}catch{status.textContent=@js(__('pos.network_error_dot'));}finally{busy=false;}
 });
})();
</script>
@endif

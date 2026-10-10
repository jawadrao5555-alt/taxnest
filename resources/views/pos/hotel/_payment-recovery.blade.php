<form hidden data-hotel-recovery-form
      data-recovery-key="hotel-confirm-v1:{{ $stay->company_id }}:{{ auth('pos')->id() }}:{{ $stay->id }}"
      data-recovery-url="{{ route('pos.hotel.bill-recovery', $stay->id) }}"
      data-confirm-url="{{ route('pos.hotel.bill-confirm', $stay->id) }}">@csrf</form>

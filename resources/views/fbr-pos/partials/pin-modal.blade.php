<div id="fbrPosPinModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm" x-data="fbrPosPinVerification()" x-cloak>
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 p-8 w-full max-w-sm mx-4">
        <div class="text-center mb-6">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Confidential PIN Required</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter your company's confidential PIN to access local data</p>
        </div>

        <div id="fbrPosPinError" class="hidden mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm text-center"></div>

        <div class="flex justify-center gap-3 mb-6">
            <input type="password" maxlength="1" class="fbr-pos-pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition" data-index="0">
            <input type="password" maxlength="1" class="fbr-pos-pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition" data-index="1">
            <input type="password" maxlength="1" class="fbr-pos-pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition" data-index="2">
            <input type="password" maxlength="1" class="fbr-pos-pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition" data-index="3">
            <input type="password" maxlength="1" class="fbr-pos-pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition hidden" data-index="4">
            <input type="password" maxlength="1" class="fbr-pos-pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition hidden" data-index="5">
        </div>

        <div class="flex gap-3">
            <button onclick="closeFbrPosPinModal()" class="flex-1 px-4 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
            <button onclick="submitFbrPosPin()" id="fbrPosPinSubmitBtn" class="flex-1 px-4 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-semibold hover:bg-blue-700 transition disabled:opacity-50" disabled>Verify</button>
        </div>
    </div>
</div>

<script>
let fbrPosPinCallback = null;

function openFbrPosPinModal(callback) {
    fbrPosPinCallback = callback;
    const modal = document.getElementById('fbrPosPinModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('fbrPosPinError').classList.add('hidden');
    document.querySelectorAll('.fbr-pos-pin-digit').forEach(d => { d.value = ''; });
    document.querySelector('.fbr-pos-pin-digit[data-index="0"]').focus();
}

function closeFbrPosPinModal() {
    const modal = document.getElementById('fbrPosPinModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    fbrPosPinCallback = null;
}

document.querySelectorAll('.fbr-pos-pin-digit').forEach(input => {
    input.addEventListener('input', function () {
        if (this.value.length === 1) {
            const next = this.nextElementSibling;
            if (next && next.classList.contains('fbr-pos-pin-digit') && !next.classList.contains('hidden')) {
                next.focus();
            }
        }
        updateFbrPosPinSubmitBtn();
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && this.value === '') {
            const prev = this.previousElementSibling;
            if (prev && prev.classList.contains('fbr-pos-pin-digit')) {
                prev.focus();
                prev.value = '';
            }
        }
        if (e.key === 'Enter') {
            submitFbrPosPin();
        }
    });
});

function updateFbrPosPinSubmitBtn() {
    const digits = document.querySelectorAll('.fbr-pos-pin-digit:not(.hidden)');
    const pin = Array.from(digits).map(d => d.value).join('');
    document.getElementById('fbrPosPinSubmitBtn').disabled = pin.length < 4;
}

function submitFbrPosPin() {
    const digits = document.querySelectorAll('.fbr-pos-pin-digit:not(.hidden)');
    const pin = Array.from(digits).map(d => d.value).join('');
    if (pin.length < 4) return;

    document.getElementById('fbrPosPinSubmitBtn').disabled = true;
    document.getElementById('fbrPosPinSubmitBtn').textContent = 'Verifying...';

    fetch('{{ route("fbrpos.api.verify-pin") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ pin: pin })
    })
    .then(r => r.json().then(data => ({ status: r.status, data })))
    .then(({ status, data }) => {
        if (data.success) {
            closeFbrPosPinModal();
            if (fbrPosPinCallback) fbrPosPinCallback();
        } else {
            document.getElementById('fbrPosPinError').textContent = data.message;
            document.getElementById('fbrPosPinError').classList.remove('hidden');
            document.querySelectorAll('.fbr-pos-pin-digit').forEach(d => { d.value = ''; });
            document.querySelector('.fbr-pos-pin-digit[data-index="0"]').focus();
        }
        document.getElementById('fbrPosPinSubmitBtn').disabled = false;
        document.getElementById('fbrPosPinSubmitBtn').textContent = 'Verify';
    })
    .catch(() => {
        document.getElementById('fbrPosPinError').textContent = 'Network error. Please try again.';
        document.getElementById('fbrPosPinError').classList.remove('hidden');
        document.getElementById('fbrPosPinSubmitBtn').disabled = false;
        document.getElementById('fbrPosPinSubmitBtn').textContent = 'Verify';
    });
}

function checkFbrPosPinSessionAndSwitch(url) {
    fetch('{{ route("fbrpos.api.check-pin-session") }}')
    .then(r => r.json())
    .then(data => {
        if (data.verified) {
            window.location.href = url;
        } else {
            openFbrPosPinModal(() => { window.location.href = url; });
        }
    });
}
</script>

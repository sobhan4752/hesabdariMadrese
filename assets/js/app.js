/**
 * سیستم مدیریت شهریه - توابع عمومی + مدیریت چهار روش تاریخ
 */
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  if (!sidebar || !overlay) return;
  sidebar.classList.toggle('translate-x-full');
  overlay.classList.toggle('hidden');
  setTimeout(() => overlay.classList.toggle('opacity-0'), 10);
}

function showToast(message, type = 'success') {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const toast = document.createElement('div');
  const base = ['pointer-events-auto','flex','items-center','gap-3','px-4','py-3','rounded-xl','shadow-xl','text-sm','font-medium','animate-fade-in-up'];
  const types = {
    success: ['bg-emerald-600','text-white'],
    error: ['bg-red-600','text-white'],
    warning: ['bg-amber-500','text-white'],
    info: ['bg-blue-600','text-white']
  };
  const selected = types[type] || types.success;
  toast.classList.add(...base, ...selected);
  const icons = {
    success: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
    error: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
    warning: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>',
    info: '<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>'
  };
  toast.innerHTML = (icons[type] || icons.info) + '<span>' + message + '</span>';
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)'; toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

document.addEventListener('input', function(e) {
  if (e.target.classList.contains('money-input')) {
    let v = e.target.value.replace(/,/g, '');
    if (!isNaN(v) && v.length > 0) e.target.value = Number(v).toLocaleString('en-US');
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
    if (!e.target.closest('form')?.querySelector('button[type="submit"]:focus')) {
      e.preventDefault();
      const focusable = Array.from(document.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])'));
      const idx = focusable.indexOf(e.target);
      if (idx > -1 && idx < focusable.length - 1) focusable[idx + 1].focus();
    }
  }
});

function showSpinner(containerId) {
  const el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '<div class="flex justify-center py-2"><svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></div>';
}
function hideSpinner(containerId) {
  const el = document.getElementById(containerId);
  if (el) el.innerHTML = '';
}

// ======================== مدیریت تاریخ ========================
function initDateInputs() {
    const method = DATE_INPUT_METHOD || 'dropdown';
    const years = VALID_YEARS || [];

    document.querySelectorAll('.datepicker-jalali').forEach(input => {
        const parent = input.parentNode;
        const name = input.getAttribute('name') || 'date';
        const value = input.value;

        input.type = 'hidden';

        if (method === 'picker') {
            input.type = 'text';
            input.setAttribute('readonly','readonly');
            if (typeof $ !== 'undefined') {
                $(input).persianDatepicker({
                    formatDate: "YYYY/MM/DD",
                    persianNumbers: true,
                    isRTL: true,
                    theme: 'default',
                    onSelect: function() { $(this).trigger('change'); }
                });
            }
        } else if (method === 'dropdown') {
            const container = document.createElement('div');
            container.className = 'flex gap-1';

            const selectDay = document.createElement('select');
            selectDay.className = 'p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm';
            for (let d=1; d<=31; d++) {
                const opt = document.createElement('option');
                opt.value = d.toString().padStart(2,'0');
                opt.textContent = d.toLocaleString('fa-IR');
                selectDay.appendChild(opt);
            }

            const selectMonth = document.createElement('select');
            selectMonth.className = 'p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm';
            ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'].forEach((m,i) => {
                const opt = document.createElement('option');
                opt.value = (i+1).toString().padStart(2,'0');
                opt.textContent = (i+1).toString().padStart(2,'0') + ' - ' + m;
                selectMonth.appendChild(opt);
            });

            const selectYear = document.createElement('select');
            selectYear.className = 'p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm';
            if (years.length === 0) years.push('1404','1405');
            years.forEach(y => {
                const opt = document.createElement('option');
                opt.value = y;
                opt.textContent = y;
                selectYear.appendChild(opt);
            });

            if (value) {
                const parts = value.split('/');
                if (parts.length === 3) {
                    selectDay.value = parts[2];
                    selectMonth.value = parts[1];
                    selectYear.value = parts[0];
                }
            }

            function updateHidden() {
                input.value = selectYear.value + '/' + selectMonth.value + '/' + selectDay.value;
            }

            selectDay.addEventListener('change', updateHidden);
            selectMonth.addEventListener('change', updateHidden);
            selectYear.addEventListener('change', updateHidden);
            updateHidden();

            container.appendChild(selectDay);
            container.appendChild(selectMonth);
            container.appendChild(selectYear);
            parent.appendChild(container);

        } else if (method === 'manual') {
            input.type = 'text';
            input.removeAttribute('readonly');
            input.placeholder = 'YYYY/MM/DD';
            input.classList.add('text-left');
            input.style.direction = 'ltr';
            if (value) input.value = value;
        } else if (method === 'manual_separate') {
            const container = document.createElement('div');
            container.className = 'flex gap-1';

            const dayInput = document.createElement('input');
            dayInput.type = 'text';
            dayInput.className = 'w-12 p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-center';
            dayInput.placeholder = 'روز';
            dayInput.maxLength = 2;

            const monthInput = document.createElement('input');
            monthInput.type = 'text';
            monthInput.className = 'w-12 p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-center';
            monthInput.placeholder = 'ماه';
            monthInput.maxLength = 2;

            const yearInput = document.createElement('input');
            yearInput.type = 'text';
            yearInput.className = 'w-16 p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-center';
            yearInput.placeholder = 'سال';
            yearInput.maxLength = 4;

            if (value) {
                const parts = value.split('/');
                if (parts.length === 3) {
                    dayInput.value = parts[2];
                    monthInput.value = parts[1];
                    yearInput.value = parts[0];
                }
            }

            function combine() {
                const d = dayInput.value.trim().padStart(2,'0');
                const m = monthInput.value.trim().padStart(2,'0');
                let y = yearInput.value.trim();
                if (y.length > 0 && y.length <= 2) {
                    const shortYear = y.padStart(2,'0');
                    const foundYear = years.find(full => full.slice(-2) === shortYear);
                    if (foundYear) {
                        y = foundYear;
                    } else {
                        y = '14' + shortYear;
                    }
                }
                if (d && m && y) {
                    input.value = y + '/' + m + '/' + d;
                }
            }

            dayInput.addEventListener('input', combine);
            monthInput.addEventListener('input', combine);
            yearInput.addEventListener('input', combine);

            dayInput.addEventListener('keydown', e => { if(e.key==='Enter') { e.preventDefault(); monthInput.focus(); } });
            monthInput.addEventListener('keydown', e => { if(e.key==='Enter') { e.preventDefault(); yearInput.focus(); } });
            yearInput.addEventListener('keydown', e => { if(e.key==='Enter') { e.preventDefault(); combine(); } });

            container.appendChild(dayInput);
            container.appendChild(monthInput);
            container.appendChild(yearInput);
            parent.appendChild(container);
        }
    });
}

document.addEventListener('DOMContentLoaded', function(){
    initDateInputs();
});
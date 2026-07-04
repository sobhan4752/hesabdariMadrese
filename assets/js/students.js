// students.js – نسخه ۳.۳ (پایدار و بدون خطا)
(function() {
    // دریافت state اولیه از تگ script که PHP چاپ کرده
    var stateScript = document.getElementById('initialState');
    var state = {
        search: '',
        class: '',
        grade: '',
        sort: 'last_name',
        order: 'ASC',
        page: 1,
        per_page: 20,
        hide_settled: 0,
        hide_small_debtors: 0,
        hide_with_payments: 0,
        active_year_id: 0
    };

    if (stateScript) {
        try {
            var init = JSON.parse(stateScript.textContent);
            state.search = init.search || '';
            state.class = init.class || '';
            state.grade = init.grade || '';
            state.sort = init.sort || 'last_name';
            state.order = init.order || 'ASC';
            state.page = parseInt(init.page) || 1;
            state.per_page = parseInt(init.per_page) || 20;
            state.hide_settled = init.hide_settled ? 1 : 0;
            state.hide_small_debtors = init.hide_small_debtors ? 1 : 0;
            state.hide_with_payments = init.hide_with_payments ? 1 : 0;
            state.active_year_id = parseInt(init.active_year_id) || 0;
        } catch(e) {
            console.error('خطا در خواندن initialState', e);
        }
    } else {
        // fallback از URL و کوکی
        var params = new URLSearchParams(window.location.search);
        state.search = params.get('search') || '';
        state.class = params.get('class') || '';
        state.grade = params.get('grade') || '';
        state.sort = params.get('sort') || 'last_name';
        state.order = params.get('order') || 'ASC';
        state.page = parseInt(params.get('page')) || 1;
        state.per_page = parseInt(params.get('per_page')) || 20;
        state.hide_settled = document.cookie.indexOf('hide_settled=1') >= 0 ? 1 : 0;
        state.hide_small_debtors = document.cookie.indexOf('hide_small_debtors=1') >= 0 ? 1 : 0;
        state.hide_with_payments = document.cookie.indexOf('hide_with_payments=1') >= 0 ? 1 : 0;
        state.active_year_id = window.__active_fiscal_year__ || 0;
    }

    // تابع کمکی برای پیدا کردن المان
    function getEl(id) { return document.getElementById(id); }

    // تابع اصلی دریافت لیست
    function fetchStudents() {
        var qs = 'action=get_students_list' +
            '&search=' + encodeURIComponent(state.search) +
            '&class=' + encodeURIComponent(state.class) +
            '&grade=' + encodeURIComponent(state.grade) +
            '&sort=' + state.sort +
            '&order=' + state.order +
            '&page=' + state.page +
            '&per_page=' + state.per_page +
            '&hide_settled=' + state.hide_settled +
            '&hide_small_debtors=' + state.hide_small_debtors +
            '&hide_with_payments=' + state.hide_with_payments;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'ajax.php?' + qs, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    getEl('studentsTableBody').innerHTML = data.table;

                    var pc = getEl('paginationContainer');
                    if (pc && data.pagination) {
                        pc.innerHTML = data.pagination;
                    } else if (data.pagination) {
                        var np = document.createElement('div');
                        np.id = 'paginationContainer';
                        np.className = 'mt-6 bg-white rounded-2xl border border-slate-200 p-4';
                        np.innerHTML = data.pagination;
                        getEl('studentsContainer').appendChild(np);
                    } else {
                        if (pc) pc.remove();
                    }

                    // اتصال مجدد رویدادها
                    attachPaginationEvents();
                    attachSortEvents();
                } catch(e) {
                    console.error('خطا در پردازش JSON', e);
                }
            } else {
                console.error('خطای سرور: ' + xhr.status);
            }
        };
        xhr.onerror = function() {
            console.error('خطای شبکه');
        };
        xhr.send();
    }

    function attachSortEvents() {
        var links = document.querySelectorAll('.sort-link');
        for (var i = 0; i < links.length; i++) {
            links[i].onclick = function(e) {
                e.preventDefault();
                state.sort = this.getAttribute('data-sort');
                state.order = (state.order === 'ASC') ? 'DESC' : 'ASC';
                state.page = 1;
                fetchStudents();
            };
        }
    }

    function attachPaginationEvents() {
        var pageLinks = document.querySelectorAll('.page-link');
        for (var j = 0; j < pageLinks.length; j++) {
            pageLinks[j].onclick = function(e) {
                e.preventDefault();
                var p = parseInt(this.getAttribute('data-page'));
                if (!isNaN(p)) {
                    state.page = p;
                    fetchStudents();
                }
            };
        }

        var perPageSelect = getEl('per_page_select');
        if (perPageSelect) {
            perPageSelect.onchange = function() {
                state.per_page = parseInt(this.value);
                state.page = 1;
                fetchStudents();
            };
        }
    }

    // اتصال رویدادهای فیلتر
    var searchInput = getEl('searchInput');
    var searchTimeout;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                state.search = searchInput.value.trim();
                state.page = 1;
                fetchStudents();
            }, 300);
        });
    }

    var classSelect = getEl('classSelect');
    if (classSelect) {
        classSelect.addEventListener('change', function() {
            state.class = this.value;
            state.page = 1;
            fetchStudents();
        });
    }

    var gradeSelect = getEl('gradeSelect');
    if (gradeSelect) {
        gradeSelect.addEventListener('change', function() {
            state.grade = this.value;
            state.page = 1;
            fetchStudents();
        });
    }

    getEl('applyFilters').onclick = function() {
        state.search = getEl('searchInput').value.trim();
        state.class = getEl('classSelect').value;
        state.grade = getEl('gradeSelect').value;
        state.page = 1;
        fetchStudents();
    };

    getEl('clearFilters').onclick = function() {
        getEl('searchInput').value = '';
        getEl('classSelect').value = '';
        getEl('gradeSelect').value = '';
        state.search = '';
        state.class = '';
        state.grade = '';
        state.page = 1;
        fetchStudents();
    };

    // چک‌باکس‌ها
    getEl('hide_settled').onchange = function() {
        state.hide_settled = this.checked ? '1' : '0';
        document.cookie = 'hide_settled=' + (this.checked ? '1' : '0') + ';path=/;SameSite=Lax;max-age=31536000';
        state.page = 1;
        fetchStudents();
    };

    getEl('hide_small_debtors').onchange = function() {
        state.hide_small_debtors = this.checked ? '1' : '0';
        document.cookie = 'hide_small_debtors=' + (this.checked ? '1' : '0') + ';path=/;SameSite=Lax;max-age=31536000';
        state.page = 1;
        fetchStudents();
    };

    getEl('hide_with_payments').onchange = function() {
        state.hide_with_payments = this.checked ? '1' : '0';
        document.cookie = 'hide_with_payments=' + (this.checked ? '1' : '0') + ';path=/;SameSite=Lax;max-age=31536000';
        state.page = 1;
        fetchStudents();
    };

    // تسویه آنی
    window.quickSettle = function(studentId) {
        if (!confirm('تسویه آنی؟')) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        if (typeof showToast === 'function') showToast(r.message, 'success');
                        fetchStudents();
                    } else {
                        if (typeof showToast === 'function') showToast(r.error || 'خطا', 'error');
                    }
                } catch(e) {}
            }
        };
        xhr.send('action=quick_settle&student_id=' + studentId + '&fiscal_year_id=' + state.active_year_id);
    };

    // اتصال اولیه
    attachSortEvents();
    attachPaginationEvents();

    // بارگذاری اولیه (در صورت نیاز، در غیر این صورت جدول با PHP پر شده)
    // اگر خواستید اولین بار هم با Ajax بیاید، این خط را فعال کنید:
    // fetchStudents();
})();
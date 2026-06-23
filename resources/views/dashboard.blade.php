<x-app-layout>
    <div
        x-data="{
            sidebarOpen: false,
            sidebarCollapsed: false,
            darkMode: false,
            activePage: 'Dashboard',
            profileOpen: false,
            notificationsOpen: false,
            quickSaleOpen: false,
            modules: [
                'Dashboard', 'Products', 'Categories', 'Units', 'Suppliers', 'Customers',
                'Purchases', 'Stock Receiving', 'Store Stock', 'Dispensing Stock', 'Stock Transfer',
                'POS Sales', 'Credit Sales', 'Expenses', 'Reports', 'Users & Roles', 'Branches', 'Settings'
            ],
            stats: [
                ['Today\'s Sales', 'TZS 8.42M', '+18.4%', 'bg-orange-50 text-build-orange dark:bg-orange-500/15 dark:text-orange-300'],
                ['Monthly Sales', 'TZS 216.8M', '+11.2%', 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300'],
                ['Total Profit', 'TZS 51.3M', '+7.9%', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
                ['Total Purchases', 'TZS 128.9M', '-2.1%', 'bg-purple-50 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300'],
                ['Store Stock Value', 'TZS 482.6M', 'Warehouse', 'bg-slate-100 text-slate-700 dark:bg-white/5 dark:text-slate-300'],
                ['Dispensing Stock Value', 'TZS 86.4M', 'Counter', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
                ['Low Stock Alerts', '23 Items', 'Action needed', 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300'],
                ['Credit Customers', '47 Active', 'TZS 32.2M due', 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300']
            ],
            products: [
                ['Simba Cement 50kg', 'HDX-CEM-050', 'Cement', 'Bag', '15,800', '18,500', '2,840', '418', '200', 'Active'],
                ['Y12 Reinforcement Bar', 'HDX-STL-Y12', 'Steel', 'Piece', '21,000', '25,500', '780', '96', '120', 'Active'],
                ['Roofing Sheet Gauge 28', 'HDX-RFG-028', 'Roofing', 'Sheet', '17,500', '22,000', '64', '18', '80', 'Low Stock'],
                ['PVC Pipe 1 inch', 'HDX-PLB-PVC1', 'Plumbing', 'Piece', '5,100', '7,000', '1,120', '260', '150', 'Active']
            ]
        }"
        x-init="$watch('darkMode', value => document.documentElement.classList.toggle('dark', value))"
        class="min-h-screen"
    >
        <div class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"></div>

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white transition-all duration-300 dark:border-slate-800 dark:bg-navy-900 lg:translate-x-0"
            :class="{ '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen, 'lg:w-24': sidebarCollapsed, 'lg:w-72': !sidebarCollapsed }"
        >
            <div class="flex h-16 items-center gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-build-orange text-lg font-black text-white shadow-lg shadow-orange-500/30">HP</div>
                <div class="min-w-0" x-show="!sidebarCollapsed" x-transition>
                    <p class="truncate text-sm font-black uppercase tracking-wide text-navy-900 dark:text-white">Hardex</p>
                    <p class="truncate text-xs font-medium text-slate-500 dark:text-slate-400">POS</p>
                </div>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <template x-for="item in modules" :key="item">
                    <button
                        type="button"
                        @click="activePage = item; sidebarOpen = false"
                        class="group flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-semibold transition"
                        :class="activePage === item ? 'bg-orange-50 text-build-orange dark:bg-orange-500/15 dark:text-orange-300' : 'text-slate-600 hover:bg-slate-100 hover:text-navy-900 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white'"
                    >
                        <span
                            class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-xs font-black"
                            :class="activePage === item ? 'bg-build-orange text-white shadow-md shadow-orange-500/25' : 'bg-slate-100 text-slate-500 group-hover:text-navy-700 dark:bg-white/5 dark:text-slate-400'"
                            x-text="item.split(' ').map(word => word[0]).join('').slice(0,2)"
                        ></span>
                        <span class="truncate" x-show="!sidebarCollapsed" x-transition x-text="item"></span>
                    </button>
                </template>
            </nav>

            <div class="border-t border-slate-200 p-4 dark:border-slate-800" x-show="!sidebarCollapsed" x-transition>
                <div class="rounded-xl bg-navy-900 p-4 text-white shadow-soft dark:bg-white/5">
                    <p class="text-sm font-bold">Main Branch</p>
                    <p class="mt-1 text-xs text-slate-300">Store: TZS 482.6M &middot; Counter: TZS 86.4M</p>
                    <div class="mt-3 h-2 rounded-full bg-white/15">
                        <div class="h-2 w-3/4 rounded-full bg-build-orange"></div>
                    </div>
                </div>
            </div>
        </aside>

        <div class="transition-all duration-300" :class="sidebarCollapsed ? 'lg:pl-24' : 'lg:pl-72'">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-navy-950/90">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <button class="grid h-10 w-10 place-items-center rounded-lg border border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300 lg:hidden" @click="sidebarOpen = true">
                        <span class="text-xl">&#9776;</span>
                    </button>
                    <button class="hidden h-10 w-10 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-white/5 lg:grid" @click="sidebarCollapsed = !sidebarCollapsed">
                        <span class="text-xl">&#9776;</span>
                    </button>

                    <div class="min-w-0 flex-1">
                        <div class="relative max-w-2xl">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">&#8981;</span>
                            <input class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none ring-build-orange/20 placeholder:text-slate-400 focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-white/5 dark:text-white" placeholder="Search products, SKU, invoice, customer, supplier...">
                        </div>
                    </div>

                    <button class="hidden rounded-xl bg-build-orange px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-500/25 hover:bg-orange-600 sm:inline-flex" @click="quickSaleOpen = true">New Sale</button>
                    <button class="grid h-10 min-w-14 place-items-center rounded-lg border border-slate-200 px-2 text-xs font-bold dark:border-slate-700" @click="darkMode = !darkMode" x-text="darkMode ? 'Light' : 'Dark'"></button>

                    <div class="relative">
                        <button class="relative grid h-10 w-10 place-items-center rounded-lg border border-slate-200 text-xs font-bold dark:border-slate-700" @click="notificationsOpen = !notificationsOpen; profileOpen = false">
                            Bell
                            <span class="absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-build-orange ring-2 ring-white dark:ring-navy-950"></span>
                        </button>
                        <div x-cloak x-show="notificationsOpen" x-transition @click.outside="notificationsOpen = false" class="absolute right-0 mt-3 w-80 rounded-xl border border-slate-200 bg-white p-3 shadow-soft dark:border-slate-700 dark:bg-navy-900">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="font-bold">Notification Center</p>
                                <span class="rounded-full bg-orange-50 px-2 py-1 text-xs font-bold text-build-orange dark:bg-orange-500/15">8 new</span>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="rounded-lg bg-red-50 p-3 text-red-700 dark:bg-red-500/10 dark:text-red-300">Cement 50kg is below reorder level.</div>
                                <div class="rounded-lg bg-amber-50 p-3 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Credit invoice HDX-1034 is due today.</div>
                                <div class="rounded-lg bg-blue-50 p-3 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">Transfer request pending approval.</div>
                            </div>
                        </div>
                    </div>

                    <div class="relative">
                        <button class="flex items-center gap-2 rounded-xl border border-slate-200 p-1.5 pr-3 dark:border-slate-700" @click="profileOpen = !profileOpen; notificationsOpen = false">
                            <div class="grid h-8 w-8 place-items-center rounded-lg bg-navy-800 text-xs font-black text-white">AM</div>
                            <span class="hidden text-sm font-bold sm:block">{{ auth()->user()->name ?? 'Admin' }}</span>
                        </button>
                        <div x-cloak x-show="profileOpen" x-transition @click.outside="profileOpen = false" class="absolute right-0 mt-3 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-soft dark:border-slate-700 dark:bg-navy-900">
                            <a class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/5" href="{{ route('profile') }}" wire:navigate>Profile</a>
                            <a class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/5" href="#">Branch Switcher</a>
                            <a class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/5" href="#">Permissions</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">Sign out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6">
                <section class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-build-orange">Laravel 13 + Livewire 3 + Volt + Tailwind CSS</p>
                        <h1 class="mt-1 text-2xl font-black tracking-tight text-navy-900 dark:text-white sm:text-3xl" x-text="activePage"></h1>
                        <p class="mt-2 max-w-3xl text-sm text-slate-500 dark:text-slate-400">Professional hardware and construction materials management for purchases, warehouse stock, dispensing counter stock, sales, credit, expenses, branches, and reporting.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-white/5 dark:text-slate-200">Export</button>
                        <button class="rounded-xl bg-navy-800 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-navy-900/20 hover:bg-navy-700">Create Record</button>
                    </div>
                </section>

                <section x-cloak x-show="activePage === 'Dashboard'">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <template x-for="card in stats" :key="card[0]">
                            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-500 dark:text-slate-400" x-text="card[0]"></p>
                                        <p class="mt-2 text-2xl font-black text-navy-900 dark:text-white" x-text="card[1]"></p>
                                    </div>
                                    <span class="rounded-lg px-2.5 py-1 text-xs font-black" :class="card[3]" x-text="card[2]"></span>
                                </div>
                            </article>
                        </template>
                    </div>

                    <div class="mt-6 grid gap-6 xl:grid-cols-3">
                        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900 xl:col-span-2">
                            <div class="mb-5 flex items-center justify-between">
                                <div>
                                    <h2 class="font-black text-navy-900 dark:text-white">Sales Trend Chart</h2>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Daily sales by Store and Dispensing Area stock source</p>
                                </div>
                                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                    <option>This Month</option>
                                    <option>Last Month</option>
                                </select>
                            </div>
                            <div class="flex h-72 items-end gap-3 rounded-xl bg-slate-50 p-4 dark:bg-navy-950/60">
                                <template x-for="bar in [48,62,54,80,72,96,88,68,92,74,84,100]" :key="bar">
                                    <div class="flex flex-1 flex-col justify-end gap-2">
                                        <div class="rounded-t-lg bg-build-orange shadow-lg shadow-orange-500/20" :style="`height: ${bar}%`"></div>
                                        <div class="h-1 rounded-full bg-navy-800/80"></div>
                                    </div>
                                </template>
                            </div>
                        </article>

                        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900">
                            <h2 class="font-black text-navy-900 dark:text-white">Top Selling Products</h2>
                            <div class="mt-5 space-y-4">
                                @foreach ([['Simba Cement 50kg', '1,248 bags', '92%'], ['Y12 Reinforcement Bar', '816 pcs', '81%'], ['Roofing Sheet Gauge 28', '604 sheets', '76%'], ['PVC Pipe 1 inch', '512 pcs', '62%']] as $product)
                                    <div>
                                        <div class="flex justify-between text-sm">
                                            <span class="font-bold">{{ $product[0] }}</span>
                                            <span class="text-slate-500">{{ $product[1] }}</span>
                                        </div>
                                        <div class="mt-2 h-2 rounded-full bg-slate-100 dark:bg-white/10">
                                            <div class="h-2 rounded-full bg-build-orange" style="width: {{ $product[2] }}"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    </div>

                    <div class="mt-6 grid gap-6 xl:grid-cols-2">
                        <x-erp-panel title="Recent Transactions">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                    <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-3">Ref</th><th>Customer</th><th>Type</th><th>Total</th><th>Status</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <tr><td class="py-3 font-bold">POS-1048</td><td>Walk-in</td><td>Cash Sale</td><td>TZS 842,000</td><td><span class="badge-success">Paid</span></td></tr>
                                        <tr><td class="py-3 font-bold">CR-221</td><td>Riverside Contractors</td><td>Credit</td><td>TZS 5,410,000</td><td><span class="badge-warning">Due</span></td></tr>
                                        <tr><td class="py-3 font-bold">PO-904</td><td>Twiga Cement Ltd</td><td>Purchase</td><td>TZS 18,700,000</td><td><span class="badge-info">Received</span></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </x-erp-panel>

                        <x-erp-panel title="Inventory Summary">
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5"><p class="text-sm text-slate-500">Products</p><p class="mt-2 text-2xl font-black">2,418</p></div>
                                <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5"><p class="text-sm text-slate-500">Categories</p><p class="mt-2 text-2xl font-black">36</p></div>
                                <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5"><p class="text-sm text-slate-500">Branches</p><p class="mt-2 text-2xl font-black">5</p></div>
                            </div>
                            <div class="mt-5 rounded-xl border border-dashed border-orange-300 bg-orange-50 p-4 text-sm text-orange-800 dark:border-orange-500/40 dark:bg-orange-500/10 dark:text-orange-200">
                                Purchase &rarr; Store Stock &rarr; Transfer to Dispensing &rarr; Sales. Authorized users can sell directly from Store Stock.
                            </div>
                        </x-erp-panel>
                    </div>
                </section>

                <section x-cloak x-show="activePage === 'Products'">
                    <x-erp-panel title="Products Module">
                        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex flex-wrap gap-2">
                                <input class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search products...">
                                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option>All Categories</option><option>Cement</option><option>Steel</option></select>
                                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option>All Statuses</option><option>Active</option><option>Low Stock</option></select>
                            </div>
                            <div class="flex gap-2">
                                <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Export PDF</button>
                                <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Export Excel</button>
                                <button class="rounded-lg bg-build-orange px-3 py-2 text-sm font-bold text-white">Add Product</button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-[1100px] w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-white/5">
                                    <tr><th class="px-4 py-3">Product</th><th>SKU/Barcode</th><th>Category</th><th>Unit</th><th>Buying</th><th>Selling</th><th>Store Qty</th><th>Dispensing Qty</th><th>Reorder</th><th>Status</th><th></th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <template x-for="p in products" :key="p[1]">
                                        <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="grid h-11 w-11 place-items-center rounded-lg bg-slate-100 text-xs font-black dark:bg-white/10">IMG</div>
                                                    <span class="font-bold" x-text="p[0]"></span>
                                                </div>
                                            </td>
                                            <td class="font-mono text-xs" x-text="p[1]"></td><td x-text="p[2]"></td><td x-text="p[3]"></td><td x-text="'TZS '+p[4]"></td><td class="font-bold" x-text="'TZS '+p[5]"></td><td x-text="p[6]"></td><td x-text="p[7]"></td><td x-text="p[8]"></td>
                                            <td><span class="rounded-full px-2.5 py-1 text-xs font-black" :class="p[9] === 'Low Stock' ? 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'" x-text="p[9]"></span></td>
                                            <td class="pr-4 text-right"><button class="rounded-lg border border-slate-200 px-3 py-1.5 font-bold dark:border-slate-700">Edit</button></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 flex items-center justify-between border-t border-slate-200 pt-4 text-sm dark:border-slate-800">
                            <span class="text-slate-500">Showing 1 to 4 of 2,418 products</span>
                            <div class="flex gap-1"><button class="rounded-lg border px-3 py-1.5 dark:border-slate-700">Prev</button><button class="rounded-lg bg-navy-800 px-3 py-1.5 text-white">1</button><button class="rounded-lg border px-3 py-1.5 dark:border-slate-700">Next</button></div>
                        </div>
                    </x-erp-panel>
                </section>

                <section x-cloak x-show="activePage === 'Stock Transfer'">
                    <div class="grid gap-6 xl:grid-cols-3">
                        <form class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900">
                            <h2 class="text-lg font-black">Create Stock Transfer</h2>
                            <div class="mt-5 space-y-4">
                                <label class="block text-sm font-bold">Transfer From Location<select class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option>Main Store Warehouse</option><option>Dispensing Area</option></select></label>
                                <label class="block text-sm font-bold">Transfer To Location<select class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option>Dispensing Area</option><option>Main Store Warehouse</option></select></label>
                                <label class="block text-sm font-bold">Product Selection<input class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-white/5" value="Simba Cement 50kg"></label>
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="block text-sm font-bold">Available Qty<input class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 dark:border-slate-700 dark:bg-white/10" value="2,840 bags" readonly></label>
                                    <label class="block text-sm font-bold">Transfer Qty<input class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" value="120"></label>
                                </div>
                                <label class="block text-sm font-bold">Notes<textarea class="mt-1 h-24 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" placeholder="Reason or approval note"></textarea></label>
                                <button type="button" class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white shadow-lg shadow-orange-500/25">Submit Transfer</button>
                            </div>
                        </form>
                        <x-erp-panel title="Transfer History Table" class="xl:col-span-2">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                    <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-3">Transfer #</th><th>Product</th><th>From</th><th>To</th><th>Qty</th><th>Approved By</th><th>Status</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <tr><td class="py-3 font-bold">TR-0901</td><td>Simba Cement 50kg</td><td>Store</td><td>Dispensing</td><td>120</td><td>Admin</td><td><span class="badge-success">Completed</span></td></tr>
                                        <tr><td class="py-3 font-bold">TR-0900</td><td>Y12 Bar</td><td>Store</td><td>Dispensing</td><td>60</td><td>Manager</td><td><span class="badge-info">Pending</span></td></tr>
                                        <tr><td class="py-3 font-bold">TR-0899</td><td>Roofing Sheet</td><td>Store</td><td>Dispensing</td><td>40</td><td>Admin</td><td><span class="badge-success">Completed</span></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </x-erp-panel>
                    </div>
                </section>

                <section x-cloak x-show="activePage === 'POS Sales'">
                    <div class="grid gap-6 xl:grid-cols-[1fr_420px]">
                        <div class="space-y-5">
                            <x-erp-panel title="POS Product Search">
                                <div class="grid gap-3 md:grid-cols-[1fr_220px_180px]">
                                    <input class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search product by name, SKU, or category">
                                    <input class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Barcode scanner input">
                                    <select class="rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm dark:border-slate-700 dark:bg-navy-950"><option>Dispensing Area</option><option>Store</option></select>
                                </div>
                            </x-erp-panel>
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ([['Simba Cement 50kg','TZS 18,500','418 counter','bg-orange-100 text-orange-700'], ['Y12 Bar','TZS 25,500','96 counter','bg-slate-100 text-slate-700'], ['PVC Pipe 1 inch','TZS 7,000','260 counter','bg-blue-100 text-blue-700'], ['Roofing Sheet Gauge 28','TZS 22,000','18 counter','bg-amber-100 text-amber-700'], ['Paint White 20L','TZS 68,000','37 counter','bg-cyan-100 text-cyan-700'], ['Nails 3 inch','TZS 4,500','310 counter','bg-emerald-100 text-emerald-700']] as $product)
                                    <button class="rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-800 dark:bg-navy-900">
                                        <div class="grid h-24 place-items-center rounded-lg text-sm font-black {{ $product[3] }}">ITEM</div>
                                        <p class="mt-3 font-black">{{ $product[0] }}</p>
                                        <div class="mt-2 flex items-center justify-between text-sm"><span class="font-bold text-build-orange">{{ $product[1] }}</span><span class="text-slate-500">{{ $product[2] }}</span></div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <aside class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-navy-900">
                            <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                                <h2 class="text-lg font-black">Shopping Cart</h2>
                                <select class="mt-3 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option>Walk-in Customer</option><option>Riverside Contractors</option><option>Credit Customer</option></select>
                            </div>
                            <div class="space-y-3 p-5">
                                <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 dark:bg-white/5"><div><p class="font-bold">Simba Cement 50kg</p><p class="text-xs text-slate-500">4 x TZS 18,500</p></div><p class="font-black">74,000</p></div>
                                <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 dark:bg-white/5"><div><p class="font-bold">Y12 Bar</p><p class="text-xs text-slate-500">2 x TZS 25,500</p></div><p class="font-black">51,000</p></div>
                            </div>
                            <div class="border-t border-slate-200 p-5 dark:border-slate-800">
                                <div class="space-y-3 text-sm">
                                    <div class="flex justify-between"><span>Subtotal</span><span>TZS 125,000</span></div>
                                    <div class="flex items-center justify-between gap-4"><span>Discount</span><input class="w-28 rounded-lg border border-slate-200 px-2 py-1 text-right dark:border-slate-700 dark:bg-navy-950" value="0"></div>
                                    <div class="flex items-center justify-between gap-4"><span>Tax</span><input class="w-28 rounded-lg border border-slate-200 px-2 py-1 text-right dark:border-slate-700 dark:bg-navy-950" value="18%"></div>
                                    <div class="flex justify-between border-t border-slate-200 pt-3 text-lg font-black dark:border-slate-800"><span>Total</span><span>TZS 147,500</span></div>
                                </div>
                                <div class="mt-5 grid grid-cols-2 gap-2">
                                    <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Cash</button>
                                    <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Mobile Money</button>
                                    <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Bank</button>
                                    <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Credit</button>
                                </div>
                                <button class="mt-3 w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white">Complete Sale & Print Receipt</button>
                            </div>
                        </aside>
                    </div>
                </section>

                <section x-cloak x-show="activePage === 'Purchases'">
                    <x-erp-panel title="Purchase Order Form">
                        <div class="grid gap-4 md:grid-cols-3">
                            <label class="text-sm font-bold">Supplier Selection<select class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option>Twiga Cement Ltd</option><option>Steel Masters Co.</option></select></label>
                            <label class="text-sm font-bold">Purchase Date<input type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></label>
                            <label class="text-sm font-bold">Reference<input class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" value="PO-2026-0912"></label>
                        </div>
                        <div class="mt-6 overflow-x-auto">
                            <table class="min-w-[850px] w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Product</th><th>Quantity</th><th>Cost Price</th><th>Tax</th><th>Total</th><th></th></tr></thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr><td class="px-3 py-3"><input class="w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" value="Simba Cement 50kg"></td><td><input class="w-28 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" value="1000"></td><td><input class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" value="15,800"></td><td>18%</td><td class="font-black">TZS 18,644,000</td><td><button class="text-red-600">Remove</button></td></tr>
                                    <tr><td class="px-3 py-3"><input class="w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" placeholder="Add product"></td><td><input class="w-28 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td><td><input class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td><td>18%</td><td class="font-black">TZS 0</td><td></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <button class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Add Row</button>
                            <div class="rounded-xl bg-slate-50 p-4 text-right dark:bg-white/5">
                                <p class="text-sm text-slate-500">Purchase Total</p>
                                <p class="text-2xl font-black">TZS 18,644,000</p>
                                <button class="mt-3 rounded-xl bg-navy-800 px-4 py-2.5 text-sm font-black text-white">Receive Stock Button</button>
                            </div>
                        </div>
                    </x-erp-panel>
                </section>

                <section x-cloak x-show="activePage === 'Reports'">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach (['Sales Reports','Purchase Reports','Profit Reports','Stock Reports','Transfer Reports','Customer Balance Reports','Supplier Balance Reports','Expense Reports'] as $report)
                            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900">
                                <div class="grid h-12 w-12 place-items-center rounded-xl bg-orange-50 text-sm font-black text-build-orange dark:bg-orange-500/15">RPT</div>
                                <h2 class="mt-4 font-black">{{ $report }}</h2>
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Filter by date, branch, supplier, customer, product, category, and stock location.</p>
                                <div class="mt-4 flex gap-2"><button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">PDF</button><button class="rounded-lg bg-navy-800 px-3 py-2 text-sm font-bold text-white">Excel</button></div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section x-cloak x-show="!['Dashboard','Products','Stock Transfer','POS Sales','Purchases','Reports'].includes(activePage)">
                    <div class="grid gap-6 xl:grid-cols-3">
                        <x-erp-panel title="Module Management" class="xl:col-span-2">
                            <p class="text-sm text-slate-500 dark:text-slate-400">Advanced data table with search, filters, sorting, pagination, and PDF/Excel exports.</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <input class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search table...">
                                <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Filters</button>
                                <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Export PDF</button>
                                <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Export Excel</button>
                            </div>
                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Name</th><th>Code</th><th>Branch</th><th>Balance/Value</th><th>Status</th><th></th></tr></thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <tr><td class="px-3 py-3 font-bold">Primary record</td><td>HDX-001</td><td>Main Branch</td><td>TZS 2,450,000</td><td><span class="badge-success">Active</span></td><td class="text-right"><button class="font-bold text-build-orange">Open</button></td></tr>
                                        <tr><td class="px-3 py-3 font-bold">Secondary record</td><td>HDX-002</td><td>North Branch</td><td>TZS 840,000</td><td><span class="badge-info">Pending</span></td><td class="text-right"><button class="font-bold text-build-orange">Open</button></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </x-erp-panel>
                        <form class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900">
                            <h2 class="text-lg font-black">Quick Form</h2>
                            <div class="mt-4 space-y-4">
                                <label class="block text-sm font-bold">Name<input class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></label>
                                <label class="block text-sm font-bold">Code<input class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></label>
                                <label class="block text-sm font-bold">Status<select class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option>Active</option><option>Inactive</option></select></label>
                                <button type="button" class="w-full rounded-xl bg-navy-800 px-4 py-3 font-black text-white">Save Record</button>
                            </div>
                        </form>
                    </div>
                </section>
            </main>
        </div>

        <div x-cloak x-show="quickSaleOpen" class="fixed inset-0 z-[60] grid place-items-center bg-slate-950/60 p-4" x-transition.opacity>
            <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-soft dark:border-slate-700 dark:bg-navy-900" @click.outside="quickSaleOpen = false">
                <div class="flex items-start justify-between">
                    <div><h2 class="text-xl font-black">Quick Sale</h2><p class="mt-1 text-sm text-slate-500">Start a POS transaction from any page.</p></div>
                    <button class="rounded-lg border border-slate-200 px-3 py-1.5 dark:border-slate-700" @click="quickSaleOpen = false">Close</button>
                </div>
                <div class="mt-5 grid gap-3">
                    <input class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 dark:border-slate-700 dark:bg-white/5" placeholder="Scan barcode or search product">
                    <select class="rounded-lg border border-slate-200 bg-white px-3 py-3 dark:border-slate-700 dark:bg-navy-950"><option>Sell from Dispensing Area</option><option>Sell from Store with authorization</option></select>
                    <button class="rounded-xl bg-build-orange px-4 py-3 font-black text-white" @click="activePage = 'POS Sales'; quickSaleOpen = false">Open POS Sales</button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

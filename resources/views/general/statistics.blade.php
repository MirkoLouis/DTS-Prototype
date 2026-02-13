<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Department Statistics') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Performance Analytics for {{ Auth::user()->department->name }}</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6"
                         data-current-load-url="{{ route('api.statistics.current-load') }}"
                         data-throughput-url="{{ route('api.statistics.throughput') }}"
                         data-avg-processing-time-url="{{ route('api.statistics.avg-processing-time') }}">

                        <!-- Current Load Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h4 class="text-lg font-bold">Documents Received</h4>
                                <select id="currentLoadPeriod" class="chart-period-selector form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (30 Days)</option>
                                    <option value="weekly">Weekly (4 Weeks)</option>
                                    <option value="monthly">Monthly (12 Months)</option>
                                </select>
                            </div>
                            <div class="relative" style="height:200px">
                                <canvas id="currentLoadChart"></canvas>
                            </div>
                        </div>

                        <!-- Average Processing Time Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h4 class="text-lg font-bold">Average Processing Time</h4>
                                <select id="avgProcessingTimePeriod" class="chart-period-selector form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (30 Days)</option>
                                    <option value="weekly">Weekly (4 Weeks)</option>
                                    <option value="monthly">Monthly (12 Months)</option>
                                </select>
                            </div>
                            <div class="relative" style="height:200px">
                                <canvas id="avgProcessingTimeChart"></canvas>
                            </div>
                        </div>

                        <!-- Throughput Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow md:col-span-2">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h4 class="text-lg font-bold">Documents Processed Over Time</h4>
                                <select id="throughputPeriod" class="chart-period-selector form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (Last 30 Days)</option>
                                    <option value="weekly">Weekly (Last 4 Weeks)</option>
                                    <option value="monthly">Monthly (Last 12 Months)</option>
                                    <option value="yearly">Yearly (Last 5 Years)</option>
                                </select>
                            </div>
                            <div class="relative" style="height:400px">
                                <canvas id="throughputChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite('resources/js/statistics.js')
    @endpush

    @if (Auth::user()->role === 'officer')
    <div class="pb-12" id="released-documents-section" data-fetch-url="{{ route('statistics.index') }}">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-2xl font-bold">Released Documents History</h3>
                        {{-- Filters and Search --}}
                        <div class="flex items-end space-x-2">
                            <div class="flex space-x-2">
                                <div class="flex-1">
                                    <label for="year-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Year</label>
                                    <select id="year-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="all">All</option>
                                        @foreach($years ?? [] as $year)
                                            <option value="{{ $year }}">{{ $year }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex-1">
                                    <label for="month-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Month</label>
                                    <select id="month-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="all">All</option>
                                        @foreach(range(1,12) as $month)
                                            <option value="{{ $month }}">{{ date('F', mktime(0, 0, 0, $month, 10)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex-1">
                                    <label for="day-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Day</label>
                                    <select id="day-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="all">All</option>
                                        @foreach(range(1,31) as $day)
                                            <option value="{{ $day }}">{{ $day }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="purpose-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Purpose</label>
                                <select id="purpose-filter" class="filter-input block w-60 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="all">All Purposes</option>
                                    @foreach($purposes as $purpose)
                                        <option value="{{ $purpose->id }}">{{ $purpose->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="submitter-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Submitter</label>
                                <select id="submitter-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="all">All Submitters</option>
                                    @foreach($submitters ?? [] as $submitter)
                                        <option value="{{ $submitter }}">{{ $submitter }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="table-search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                                <input type="text" id="table-search" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" placeholder="Tracking code or name...">
                            </div>
                            <button id="clear-filters-btn" class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:border-gray-700 focus:ring focus:ring-gray-200 disabled:opacity-25 transition">
                                Clear
                            </button>
                        </div>
                    </div>
                    
                    <div id="released-documents-container" class="overflow-x-auto">
                        @include('officer.partials.released-documents-table', ['releasedDocuments' => $releasedDocuments])
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</x-app-layout>

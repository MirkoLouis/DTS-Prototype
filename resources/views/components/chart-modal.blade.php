<!--
A generic, reusable modal for displaying a larger version of a chart.
-->
<div id="chart-modal" style="display:none;" class="fixed inset-0 bg-gray-600 bg-opacity-75 z-50 transition-opacity flex items-center justify-center p-4">
    <div class="relative p-5 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white dark:bg-gray-800 max-h-full overflow-y-auto">
        
        <!-- Modal Header -->
        <div class="flex justify-between items-center pb-3 border-b dark:border-gray-600">
            <h3 id="chart-modal-title" class="text-2xl font-bold text-gray-900 dark:text-gray-100">Chart</h3>
            <button id="close-chart-modal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none">
                <span class="text-2xl font-bold">&times;</span>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="mt-5">
            <canvas id="modal-chart-canvas"></canvas>
        </div>
    </div>
</div>

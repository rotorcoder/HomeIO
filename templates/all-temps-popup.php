<!-- templates/all-temps-popup.php -->
<div id="all-temps-popup" class="popup-overlay" style="display: none;">
    <div class="popup-container">
        <div class="popup-header">
            <h3>All Temperature History</h3>
            <button onclick="hideAllTempsPopup()" class="close-popup-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="popup-content">
            <div class="history-controls">
                <select id="all-temps-history-range" onchange="loadAllTempHistory()">
                    <option value="24">Last 24 Hours</option>
                    <option value="168">Last 7 Days</option>
                    <option value="720">Last 30 Days</option>
                </select>
            </div>
            <div class="chart-container" style="position: relative; height: 400px; width: 100%;">
                <canvas id="all-temps-chart"></canvas>
            </div>
            <div class="thermometer-table" style="margin-top: 20px;">
                <h4>Thermometer Management</h4>
                <table class="therm-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>Display Name</th>
                            <th>Name</th>
                            <th>MAC</th>
                            <th>Model</th>
                            <th>Room</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="thermometer-list">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
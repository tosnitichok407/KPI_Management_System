/* ==========================================
         ICONS
      ========================================== */

        lucide.createIcons();

        /* ==========================================
         KPI CHART
      ========================================== */

        const ctx = document.getElementById("kpiChart");

        new Chart(ctx, {
            type: "line",

            data: {
                labels: [
                    "ม.ค.",
                    "ก.พ.",
                    "มี.ค.",
                    "เม.ย.",
                    "พ.ค.",
                    "มิ.ย.",
                    "ก.ค.",
                    "ส.ค.",
                ],

                datasets: [
                    {
                        label: "คะแนน KPI เฉลี่ย",

                        data: [72, 76, 74, 81, 79, 84, 82, 86],

                        borderColor: "#ED4924",

                        backgroundColor: "rgba(237,73,36,0.10)",

                        borderWidth: 3,

                        tension: 0.4,

                        fill: true,

                        pointRadius: 4,

                        pointHoverRadius: 7,
                    },

                    {
                        label: "เป้าหมาย",

                        data: [80, 80, 80, 80, 80, 80, 80, 80],

                        borderColor: "#3D4D64",

                        borderWidth: 2,

                        borderDash: [6, 6],

                        pointRadius: 0,

                        tension: 0,
                    },
                ],
            },

            options: {
                responsive: true,

                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        position: "top",

                        align: "end",

                        labels: {
                            usePointStyle: true,

                            font: {
                                family: "Kanit",
                            },
                        },
                    },
                },

                scales: {
                    y: {
                        min: 0,

                        max: 100,

                        ticks: {
                            callback: function (value) {
                                return value + "%";
                            },

                            font: {
                                family: "Kanit",
                            },
                        },

                        grid: {
                            color: "#EEF0F3",
                        },
                    },

                    x: {
                        grid: {
                            display: false,
                        },

                        ticks: {
                            font: {
                                family: "Kanit",
                            },
                        },
                    },
                },
            },
        });
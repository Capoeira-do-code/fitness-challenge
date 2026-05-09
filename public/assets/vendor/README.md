Vendored browser libraries used by the Profile PDF export.

- `jspdf.umd.min.js`: jsPDF 2.5.1, loaded on demand by `/assets/main.js`.
- `jspdf.plugin.autotable.min.js`: jsPDF AutoTable 3.8.4, loaded after jsPDF for table pagination.
- `chart.umd.min.js`: Chart.js 4.4.3, loaded on demand to render chart images into the PDF.

These files keep PDF export working without runtime CDN access.

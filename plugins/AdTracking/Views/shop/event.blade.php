<script>
  (function () {
    const eventData = @json($event ?? []);
    const emit = function () {
      if (window.beikeAdTracking && eventData.name) {
        window.beikeAdTracking.track(eventData.name, eventData.params || {});
        return;
      }
      window.setTimeout(emit, 50);
    };
    emit();
  })();
</script>

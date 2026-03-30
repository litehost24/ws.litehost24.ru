window.onload = () => {
  let elements = document.getElementsByClassName("delete-confirm");
  Array.from(elements).forEach((element) => {
    element.addEventListener("click", (e) => {
      e.preventDefault();
      if (confirm("Р’С‹ СѓРІРµСЂРµРЅС‹, С‡С‚Рѕ С…РѕС‚РёС‚Рµ РѕС‚РєР»СЋС‡РёС‚СЊ СѓСЃР»СѓРіСѓ?")) {
        window.location.href = e.target.href;
      }
    });
  });
}

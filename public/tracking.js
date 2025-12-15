document.addEventListener('click', (event) => {
  const link = event.target.closest('a');
  if (!link || !link.href) return;

  const href = link.getAttribute('href');
  const docidRegex = /docid=\d+/;

  if (!docidRegex.test(href)) return;

  $.ajax({
    url: '/plugins/accesstransparency/ajax/userinteractions.php',
    type: 'POST',
    data: {
      action: 'register',
      ruta: href
    },
    success: function (response) {
      console.log(response);
    }
  });
});

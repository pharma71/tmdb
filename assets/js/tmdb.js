document.addEventListener("DOMContentLoaded", function () {

    let genreList = sessionStorage.getItem('tmdb_genre');

    if(!genreList){

        fetch('https://api.themoviedb.org/3/genre/movie/list')
        
    }
    
})
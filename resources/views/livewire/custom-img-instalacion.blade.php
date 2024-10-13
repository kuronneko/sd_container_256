<div>
    <style>
        .image-container {
            display: flex;
            flex-wrap: wrap;
            margin: -10px; /* Adjust margin as needed to create spacing between images */
        }

        .image-item {
            position: relative; /* Ensure relative positioning for proper placement of the delete button */
            flex: 0 0 calc(33.3333% - 20px); /* Adjust the percentage and margin as needed */
            margin: 10px; /* Adjust margin as needed to create spacing between images */
            text-align: center;
            width: 200px; /* Set a fixed width for the container */
            height: 200px; /* Set a fixed height for the container */
            overflow: hidden; /* Hide overflowing content */
            border: 1px solid #303032; /* Add a border or remove as needed */
            border-radius: 4px; /* Add border-radius or remove as needed */
        }

        .image-item a {
            display: block;
            background-color: white;
            text-decoration: none;
        }

        .image-item img {
            width: auto; /* Allow the image to adjust its width */
            height: auto; /* Allow the image to adjust its height */
            max-width: 100%; /* Ensure the image doesn't exceed the container width */
            max-height: 100%; /* Ensure the image doesn't exceed the container height */
        }

        .delete-button {
            position: absolute;
            bottom: 5px;
            right: 5px; /* Position the delete button at the bottom right corner */
        }
    </style>

    <div class="image-container">
        @foreach($linea->instalacion?->imagenes as $imagen)
            <div class="image-item">
                <span class="text-sm">Tipo: {{$imagen->tipo}}</span> - <span class="text-xs">{{$imagen->created_at}}</span>
                <a href="{{ asset(Storage::url($imagen->url)) }}" target="_blank">
                    <img src="{{ asset(Storage::url($imagen->url)) }}" alt="No se pudo cargar la imagen.">
                </a>
                @if ($edit)
                    <div class="delete-button">
                        {{ ($this->deleteAction)(['imagenId' => $imagen->id]) }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>


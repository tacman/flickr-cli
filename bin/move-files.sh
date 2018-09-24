#!/bin/bash
ALLFILES=(~/Pictures/all-by-mistake/*)
for ((i=0; i<${#ALLFILES[*]}; i+=30000));
do
    set $(echo "${ALLFILES[@]:i:30000}" | awk -F_ '{print $1, $2, $3, $4, $5}')
    fullyear=$3
    echo $fullyear
    year=$(echo $fullyear |cut -c1-4)
    month=$(echo $fullyear |cut -c5-6)
    day=$(echo $fullyear |cut -c7-8)
    hour=$(echo $fullyear |cut -c9-10)
    newdir=$(echo /home/tac/sorted/$year/$month/$day/$hour/)
    if ! [ -d $newdir ]; then
        echo Directory $newdir does not exist.  Creating same.
        # mkdir -p $newdir;
    fi
    # mv "${ALLFILES[@]:i:30000}" $newdir;
done
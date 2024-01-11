# Zinc.NeosSearch

A lightweight fulltext and term search for [Neos CMS](https://www.neos.io/).

Project goals:
- Simple to set up
- Minimal hardware requirements
- Enable professional features like fast server side Node-Querying for "cheap"

## Quickstart

### Start Zinc Search Index
See [Zinc Documentation](https://docs.zinclabs.io/04_installation/).
```
# DDEV config in .ddev/docker-compose.zincsearch.yaml:
version: '3.6'
services:
  zincsearch:
    container_name: ddev-${DDEV_SITENAME}-zincsearch
    hostname: ${DDEV_SITENAME}-zincsearch
    image: public.ecr.aws/zinclabs/zincsearch:0.4.9
    expose:
      - "4080"
    ports:
      - "4080"
    environment:
      - ZINC_FIRST_ADMIN_USER=admin
      - ZINC_FIRST_ADMIN_PASSWORD=Complexpass#123
      - ZINC_DATA_PATH=/usr/share/zincsearch/data
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: $DDEV_APPROOT
    volumes:
      - ./zinc:/usr/share/zincsearch
      - ".:/mnt/ddev_config"

volumes:
  zincsearch:
```

### Install and configure Zinc.NeosSearch
```
composer require zinc/neos-search
```

Settings.Zinc.yaml:
```
Zinc:
  NeosSearch:
    hostname: 'ddev-YOUR_DDEV_SITENAME-zincsearch'
```

### Configure NodeTypes
See [How to index NodeType Properties](#how-to-index-nodetype-properties).
```
Neos.Neos:Document:
  properties:
    title:
      search:
        zinc:
          mappingType: 'text'
          indexingValue: ${node.properties.title}
          fulltextValue: ${node.properties.title}

'Neos.Demo:Content.Text':
  properties:
    text:
      search:
        zinc:
          mappingType: 'text'
          fulltextValue: ${node.properties.text}

'Neos.NodeTypes.Navigation:Navigation':
  properties:
    selection:
      search:
        zinc:
          mappingType: 'keyword'
          indexingValue: ${value}

'Neos.Neos:Node':
  properties:
    '_creationDateTime':
      search:
        zinc:
          mappingType: 'date'
          indexingValue: ${value}

// ...
```

### Index the data
```
./flow zinc:index
```

### Enable the fulltext content NodeType
```
'Zinc.NeosSearch:Content.Search':
  abstract: false
```

[Or create server side queries](Resources/Private/Fusion/Examples/QueryData.fusion).

### Now the search should work 🎉

## FAQ / How To

### How to index NodeType Properties

Each property can be configured manually via
```
Vendor:NodeType:
  properties:
    aPropertyName:
      search:
        zinc: []
```

Available options:
```
# If configured, this type is used for the field:
mappingType: 'text', 'bool', 'keyword' or 'date'

# If configured, this value is added to the index:
indexingValue: ${value}
or specific..
indexingValue: ${node.properties.aPropertyName}

# If Configured, this value is added to the closest document fulltext field:
indexingValue: ${value}
or specific..
fulltextValue: ${node.properties.aPropertyName}
```

### Enable the Queue Indexer

Settings.Zinc.yaml:
```
Zinc:
  NeosSearch:
    realtimeIndexing:
      enabled: true
      queue: true

# You can enable realtimeIndexing without queue.. but this has a performance impact while publishing content!
```

Create the queue once..

```
./flow queue:setup zincBatchIndexer
```

Fill the queue regularly..
```
./flow zinc:index --queue
```

Work off queue continously..
```
./flow job:work zincBatchIndexer

# If you need to run the job via cronjob,
you can keep the job worker running once with flock:
*/5 * * * * /usr/bin/flock --nonblock --no-fork /tmp/Flow/job-queue-worker-zincBatchIndexer.lockfile -c 'FLOW_CONTEXT=Production /home/www-data/project.com/releases/current/flow job:work --queue zincBatchIndexer --exit-after 1800'
```

### Query builder Prototypes:

Example:
```
zincQueryObject = afx`
  <Zinc.NeosSearch:Query>
    <Zinc.NeosSearch:Query.Must>
      <Zinc.NeosSearch:Query.Term property="identifier" term="e9ffe2fe-e6d7-7025-50f1-6ecf2fa353c5" />
    </Zinc.NeosSearch:Query.Must>
  </Zinc.NeosSearch:Query>
`
```

Available Prototypes:

- Zinc.NeosSearch:Query, required 
  - Default props
    - from = 0
    - size = 10
    - page = 0 (replaces `from`)
    - sort = null (ex. `[{'properties__creationDateTime': 'desc'}]`)
    - stringify = false
- Zinc.NeosSearch:Query.Should
  - Expects one or more nested Prototypes
- Zinc.NeosSearch:Query.Must
  - Expects one or more nested Prototypes
- Zinc.NeosSearch:Query.MustNot
  - Expects one or more nested Prototypes
- Zinc.NeosSearch:Query.Match
  - Default props
    - field = null
    - property = null (replaces `field`)
    - query = null
    - fuzziness = 'AUTO'
    - boost = null
- Zinc.NeosSearch:Query.Prefix
  - Default Props
    - field = null
    - term = null
- Zinc.NeosSearch:Query.Term
  - Default Props
    - field = null
    - term = null
- Zinc.NeosSearch:Query.Terms
  - Default Props
    - field = null
    - terms = null

### Execute the search query

Get Nodes:
```
result = ${ZincSearch.nodes(site, this.queryBuilder)} # does return an array of nodes
#    Array (
#        ... NodeInterfase items
```

Get a raw result:
```
result = ${ZincSearch.raw(site, this.queryBuilder)}
#    [hits] => Array (
#            [total] => Array (
#                    [value] => 4
#                    [pages] => 1
#                )
#            [max_score] => 6.8515092967801
#            [hits] => Array (
#                ... raw search result items
```

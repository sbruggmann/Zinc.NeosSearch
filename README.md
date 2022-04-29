# Zinc.NeosSearch

A lightweight fulltext and term search for [Neos CMS](https://www.neos.io/). **Alpha**

Project goals:
- Simple to set up
- Minimal hardware requirements
- Enable professional features like fast server side Node-Querying for "cheap"

## Quickstart

### Start Zinc Search Index
See [Zinc Documentation](https://docs.zinclabs.io/04_installation/).
```
# Go:
mkdir data
ZINC_FIRST_ADMIN_USER=admin ZINC_FIRST_ADMIN_PASSWORD=Complexpass#123 ./zinc
```
or
```
# Docker:
mkdir data
docker run -v /path/to/zinc/data:/data -e DATA_PATH="/data" -p 4080:4080 -e ZINC_FIRST_ADMIN_USER=admin -e ZINC_FIRST_ADMIN_PASSWORD=Complexpass#123 --name zinc public.ecr.aws/h9e2j3o7/zinc:0.1.9
```

### Install and configure Zinc.NeosSearch
```
composer require zinc/neos-search
```

Settings.Zinc.yaml:
```
Zinc:
  NeosSearch:
    hostname: '123.123.123.123'
```

### Configure NodeTypes
See [How to index NodeType Properties](#-how-to-index-nodetype-properties).
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

[Or create server side queries](DistributionPackages/Zinc.NeosSearch/Resources/Private/Fusion/Examples/QueryData.fusion).

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
mappingType: 'text', 'bool' or 'keyword'

# If configured, this value is added to the index:
indexingValue: ${node.properties.aPropertyName}

# If Configured, this value is added to the closest document fulltext field:
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

- Zinc.NeosSearch:Query.Term
  - Default Props
    - field = null
    - term = null

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
